'use strict';
/**
 * Relais WhatsApp multi-numéros pour le CRM ISPAG.
 *
 * Chaque « ligne » est un numéro WhatsApp relié comme appareil lié (comme WhatsApp Web) : on scanne un QR code depuis
 * le téléphone, puis les messages reçus ET envoyés (depuis le téléphone ou le CRM) sont transmis au site par webhook.
 * Aucun coût d'API. Voir README.md pour les limites (protocole non officiel).
 */
require('dotenv').config();
const crypto  = require('crypto');
const express = require('express');
const QRCode  = require('qrcode');

const PORT       = parseInt(process.env.PORT || '3100', 10);
const API_KEY    = process.env.BRIDGE_API_KEY || '';
const HOOK_URL   = process.env.WP_WEBHOOK_URL || '';
const HOOK_SECRET = process.env.WP_WEBHOOK_SECRET || '';
const SESSIONS   = process.env.SESSIONS_DIR || './sessions';
const FAKE       = process.env.BRIDGE_FAKE === '1';

if (!API_KEY) { console.error('BRIDGE_API_KEY manquant'); process.exit(1); }

/** @type {Map<string, {id:string,label:string,status:string,qr:string|null,phone:string|null,client:any}>} */
const lines = new Map();

// ------------------------------------------------------------------ webhook vers WordPress

async function notifyWordPress(payload) {
  if (!HOOK_URL) return;
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      const res = await fetch(HOOK_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'x-webhook-secret': HOOK_SECRET },
        body: JSON.stringify(payload),
      });
      if (res.ok) return;
      console.warn(`[webhook] HTTP ${res.status} (essai ${attempt})`);
    } catch (e) {
      console.warn(`[webhook] ${e.message} (essai ${attempt})`);
    }
    await new Promise(r => setTimeout(r, attempt * 2000));
  }
  console.error('[webhook] abandon pour le message', payload.id);
}

// ------------------------------------------------------------------ clients WhatsApp

function makeClient(id) {
  if (FAKE) return require('./fake-client')(id);
  const { Client, LocalAuth } = require('whatsapp-web.js');
  return new Client({
    authStrategy: new LocalAuth({ clientId: id, dataPath: SESSIONS }),
    puppeteer: { headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'] },
  });
}

const PHONE_RE = /^[0-9]{6,20}$/;

/** Numéro « propre » (chiffres, indicatif inclus) d'un identifiant WhatsApp, null pour groupes / statuts / canaux. */
async function phoneOf(msg, outgoing) {
  const jid = outgoing ? msg.to : msg.from;
  if (!jid || !jid.endsWith('@c.us') && !jid.endsWith('@lid')) return null; // groupes (@g.us), statuts, canaux : ignorés
  if (jid.endsWith('@c.us')) return jid.replace('@c.us', '');
  try {
    const c = await (outgoing ? (await msg.getChat()).getContact() : msg.getContact());
    return c && c.number ? String(c.number) : null;
  } catch (e) { return null; }
}

async function handleMessage(line, msg, outgoing) {
  try {
    if (msg.isStatus || msg.broadcast) return;
    const phone = await phoneOf(msg, outgoing);
    if (!phone || !PHONE_RE.test(phone)) return;
    let name = null;
    if (!outgoing) {
      try { const c = await msg.getContact(); name = c.pushname || c.name || null; } catch (e) { /* facultatif */ }
    }
    await notifyWordPress({
      line: line.id,
      id: msg.id && msg.id._serialized ? msg.id._serialized : String(msg.id),
      direction: outgoing ? 'out' : 'in',
      phone,
      contact_name: name,
      body: msg.body || '',
      has_media: !!msg.hasMedia,
      media_type: msg.hasMedia ? (msg.type || 'media') : null,
      created_at: (msg.timestamp ? msg.timestamp : Math.floor(Date.now() / 1000)) * 1000,
    });
  } catch (e) {
    console.error(`[${line.id}] message ignoré:`, e.message);
  }
}

function startLine(id, label) {
  if (lines.has(id)) return lines.get(id);
  const line = { id, label: label || id, status: 'initializing', qr: null, phone: null, client: makeClient(id) };
  lines.set(id, line);
  const c = line.client;

  c.on('qr', async qr => { line.status = 'qr'; line.qr = await QRCode.toDataURL(qr, { margin: 1, width: 280 }); });
  c.on('authenticated', () => { line.status = 'authenticated'; line.qr = null; });
  c.on('ready', () => { line.status = 'ready'; line.qr = null; line.phone = c.info && c.info.wid ? c.info.wid.user : null; console.log(`[${id}] connecté`, line.phone || ''); });
  c.on('auth_failure', m => { line.status = 'auth_failure'; console.error(`[${id}] échec d'authentification:`, m); });
  c.on('disconnected', reason => { line.status = 'disconnected'; line.qr = null; console.warn(`[${id}] déconnecté:`, reason); });
  c.on('message', m => handleMessage(line, m, false));          // reçus
  c.on('message_create', m => { if (m.fromMe) handleMessage(line, m, true); }); // envoyés (téléphone ou CRM)

  Promise.resolve(c.initialize()).catch(e => { line.status = 'error'; console.error(`[${id}] initialisation impossible:`, e.message); });
  return line;
}

async function stopLine(id, logout) {
  const line = lines.get(id);
  if (!line) return false;
  try { if (logout && typeof line.client.logout === 'function') await line.client.logout(); } catch (e) { /* déjà déconnecté */ }
  try { await line.client.destroy(); } catch (e) { /* ignore */ }
  lines.delete(id);
  return true;
}

const publicLine = l => ({ id: l.id, label: l.label, status: l.status, phone: l.phone, qr: l.status === 'qr' ? l.qr : null });

// ------------------------------------------------------------------ API HTTP

const app = express();
app.use(express.json({ limit: '1mb' }));

app.use((req, res, next) => {
  const got = Buffer.from(String(req.get('x-api-key') || ''));
  const exp = Buffer.from(API_KEY);
  if (got.length !== exp.length || !crypto.timingSafeEqual(got, exp)) return res.status(401).json({ success: false, error: 'Unauthorized' });
  next();
});

// Relance des lignes enregistrées à chaque démarrage (les sessions sont conservées dans SESSIONS_DIR)
const fs = require('fs');
const LINES_FILE = require('path').join(SESSIONS, 'lines.json');
const persist = () => { try { fs.mkdirSync(SESSIONS, { recursive: true }); fs.writeFileSync(LINES_FILE, JSON.stringify([...lines.values()].map(l => ({ id: l.id, label: l.label })))); } catch (e) { console.error('persistance:', e.message); } };
app.use((req, res, next) => { res.on('finish', () => { if (req.method !== 'GET') persist(); }); next(); });
const validId = id => typeof id === 'string' && /^[a-z0-9_-]{2,40}$/.test(id);

app.get('/status', (req, res) => {
  const all = [...lines.values()].map(publicLine);
  res.json({ status: all.some(l => l.status === 'ready') ? 'ready' : (all[0] ? all[0].status : 'no_line'), lines: all });
});

app.get('/lines/:id', (req, res) => {
  const l = lines.get(req.params.id);
  if (!l) return res.status(404).json({ success: false, error: 'Unknown line' });
  res.json({ success: true, line: publicLine(l) });
});

app.post('/lines', (req, res) => {
  const { id, label } = req.body || {};
  if (!validId(id)) return res.status(400).json({ success: false, error: 'Invalid line id (a-z, 0-9, - _ ; 2-40 chars)' });
  res.json({ success: true, line: publicLine(startLine(id, label)) });
});

app.delete('/lines/:id', async (req, res) => {
  const ok = await stopLine(req.params.id, req.query.logout !== '0');
  res.status(ok ? 200 : 404).json({ success: ok });
});

app.post('/send', async (req, res) => {
  const { line: lineId, phone, message } = req.body || {};
  const digits = String(phone || '').replace(/\D/g, '');
  if (!PHONE_RE.test(digits) || !message) return res.status(400).json({ success: false, error: 'phone and message are required' });
  const line = lineId ? lines.get(lineId) : [...lines.values()].find(l => l.status === 'ready');
  if (!line) return res.status(404).json({ success: false, error: 'Line not found' });
  if (line.status !== 'ready') return res.status(409).json({ success: false, error: `Line not connected (${line.status})` });
  try {
    const sent = await line.client.sendMessage(`${digits}@c.us`, String(message));
    res.json({ success: true, id: sent && sent.id ? sent.id._serialized : null, line: line.id });
  } catch (e) {
    res.status(500).json({ success: false, error: e.message });
  }
});

// Mode test : simule un message entrant / une connexion
if (FAKE) {
  app.post('/_fake/incoming', async (req, res) => {
    const l = lines.get(req.body.line);
    if (!l) return res.status(404).json({ success: false });
    l.client.emit('message', { id: { _serialized: 'fake_' + Date.now() }, from: String(req.body.phone) + '@c.us', body: req.body.body || 'Hello', timestamp: Math.floor(Date.now() / 1000), getContact: async () => ({ pushname: req.body.name || 'Fake' }) });
    res.json({ success: true });
  });
}

try { for (const l of JSON.parse(fs.readFileSync(LINES_FILE, 'utf8'))) startLine(l.id, l.label); } catch (e) { /* premier démarrage */ }


app.listen(PORT, '127.0.0.1', () => console.log(`Relais WhatsApp ISPAG sur 127.0.0.1:${PORT}${FAKE ? ' (MODE TEST)' : ''}`));
