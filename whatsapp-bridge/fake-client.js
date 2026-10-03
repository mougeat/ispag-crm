'use strict';
// Client simulé (BRIDGE_FAKE=1) : émet un QR puis « ready » ; sendMessage renvoie un identifiant fictif.
const EventEmitter = require('events');
module.exports = function fakeClient(id) {
  const c = new EventEmitter();
  c.info = { wid: { user: '41790000000' } };
  c.initialize = async () => {
    setTimeout(() => c.emit('qr', 'fake-qr-' + id), 100);
    setTimeout(() => { c.emit('authenticated'); c.emit('ready'); }, 4000);
  };
  c.sendMessage = async (to, body) => ({ id: { _serialized: 'fake_out_' + Date.now() }, to, body });
  c.logout = async () => {};
  c.destroy = async () => {};
  return c;
};
