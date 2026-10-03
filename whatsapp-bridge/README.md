# Relais WhatsApp ISPAG (gratuit, multi-numéros)

Relie un ou plusieurs numéros WhatsApp au CRM. Chaque numéro est un **appareil lié** (comme WhatsApp Web) : on scanne
un QR code une fois, ensuite tous les messages **reçus et envoyés** (depuis le téléphone ou depuis le CRM) sont
enregistrés dans le CRM et affichés dans l'onglet **WhatsApp** de la fiche contact. Aucun abonnement, aucune API payante.

## Limites à connaître (important)

- Ce mode utilise le protocole de WhatsApp Web via la bibliothèque `whatsapp-web.js`. **Il n'est pas officiel** : les
  conditions d'utilisation de WhatsApp ne l'autorisent pas formellement et un numéro peut être bloqué, surtout en cas
  d'envois en masse. Usage prévu : conversations individuelles avec des clients qui vous connaissent. Pas de
  diffusion, pas de messages à des inconnus, pas de relances automatiques.
- Le téléphone doit rester allumé et se reconnecter à internet au moins une fois tous les 14 jours (règle des appareils liés).
- Les groupes, statuts et canaux sont ignorés. Les pièces jointes sont signalées (📎) mais pas copiées.
- L'alternative officielle est l'API WhatsApp Business Cloud de Meta : réponses gratuites dans les 24 h suivant un message
  du client, mais messages initiés par l'entreprise payants, vérification de l'entreprise et numéro dédié. Elle demanderait
  un autre module.

## Installation (une fois)

Il faut une machine allumée en permanence (petit serveur, Raspberry Pi, ou le serveur ISPAG) avec **Node.js 18+** et les
bibliothèques de Chromium (Debian/Ubuntu : `sudo apt install -y chromium libgbm1 libnss3 libatk-bridge2.0-0 libxkbcommon0 libasound2`).

```bash
cd whatsapp-bridge
npm install
cp .env.example .env     # puis éditer .env (clé API, secret, adresse du webhook)
npm start                # ou, en service : npm i -g pm2 && pm2 start server.js --name ispag-wa && pm2 save && pm2 startup
```

1. Placez un reverse proxy HTTPS (Caddy ou nginx) devant `127.0.0.1:3100` (le relais n'écoute qu'en local) et donnez-lui
   une adresse, par exemple `https://wa.votre-domaine.ch`.
2. Dans WordPress : **Réglages > WhatsApp CRM** : saisissez l'adresse du relais, la clé API (`BRIDGE_API_KEY`) et le secret
   webhook (`WP_WEBHOOK_SECRET`) identiques à ceux du `.env`, puis enregistrez. L'adresse à mettre dans
   `WP_WEBHOOK_URL` est affichée sur cette même page.
3. Même page, « Ajouter un numéro » : donnez un nom (Ventes, Achats…) puis **Connecter**. Un QR code s'affiche : sur le
   téléphone de ce numéro, WhatsApp > Réglages > Appareils liés > Associer un appareil, puis scannez. Le statut passe à
   « Connecté ». Répétez pour chaque numéro.

## Utilisation

- Un contact est reconnu par son numéro (champ téléphone de la fiche, les 9 derniers chiffres comptent). Les messages
  d'un numéro inconnu sont listés dans « Conversations sans contact » : créez le contact et l'historique apparaît.
- Avec plusieurs numéros, la fiche contact propose le choix du numéro d'envoi.
- Sauvegardez le dossier `sessions/` (sessions WhatsApp) et ne le publiez jamais : il équivaut à un accès au compte.

## Test sans WhatsApp

`npm run fake` démarre le relais en mode simulé (connexion fictive, `POST /_fake/incoming` pour simuler un message).

## API (réservée au site, en-tête `x-api-key`)

| Appel | Rôle |
| --- | --- |
| `GET /status` | Lignes et leur statut (`qr`, `ready`…) ; le QR code est un PNG en data-URL |
| `POST /lines {id,label}` | Démarre une ligne |
| `DELETE /lines/:id` | Déconnecte et supprime la session |
| `POST /send {line,phone,message}` | Envoie un message depuis une ligne |

Webhook vers WordPress (`x-webhook-secret`) : `{line,id,direction(in|out),phone,contact_name,body,has_media,media_type,created_at}`.
