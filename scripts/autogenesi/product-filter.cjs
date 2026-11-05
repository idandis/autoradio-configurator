const normalize = text => String(text).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();

const eligibleOption = label => {
  const text = normalize(label);
  if (!text || /360/.test(text)) return false;
  if (/\b(?:solo marco|solo cable|sin radio|no radio|only frame|frame only|cable only|canbus only|fascia only)\b/.test(text)) return false;
  return !/\b(?:marco|frame|carcasa)\b/.test(text) || /\d+\s*(?:gb|g)\b|\b[248]\s*(?:core|nucle)/.test(text);
};

const relevant = (title, vehicle, requireYears = true) => {
  const text = normalize(title);
  const words = text.split(' ');
  if (![vehicle.brand, vehicle.model].flatMap(v => normalize(v).split(' ')).every(w => words.includes(w))) return false;
  if (/\b(?:universal|universales|portatil|portable|protector)\b/.test(text)) return false;
  if (/^(?:(?:kit|juego|conjunto|accesorio|para)\s+)*(?:marco|frame|fascia|carcasa|cable|adaptador|canbus|protector|camara|camera|modulo|module)\b/.test(text)) return false;
  if (!/radio|stereo|estereo|navegador|multimedia|head unit|pantalla|android/.test(text)) return false;
  if (!requireYears) return true;
  const years = [...String(title).matchAll(/\b(?:19|20)\d{2}\b/g)].map(m => Number(m[0]));
  return years.length > 0 && Math.min(...years) <= vehicle.year_from && Math.max(...years) >= vehicle.year_to;
};

module.exports = { normalize, eligibleOption, relevant };
