'use strict';

/**
 * WhatsApp Business labels of one login, as the account's app state reports them (labels.edit). The CRM asks for a
 * label by name; WhatsApp's default ones are matched in Indonesian or English ("Prospek" = "Lead"), never by
 * creating a new label with the same name.
 */

// Names that mean the same default label. The first of each list is how the CRM names it.
const SAME = [
  ['prospek', 'lead', 'calon pelanggan'],
  ['order baru', 'new order', 'pesanan baru'],
  ['lunas', 'paid', 'dibayar', 'sudah dibayar'],
];

const normalize = (name) => String(name ?? '').toLowerCase().normalize('NFKC').replace(/\s+/g, ' ').trim();

/** The names that count as `wanted`: itself and, for a default label, its other spellings. */
function namesFor(wanted) {
  const w = normalize(wanted);
  const group = SAME.find((names) => names.includes(w));

  return group ? [w, ...group.filter((n) => n !== w)] : [w];
}

/**
 * The label `wanted` names among `labels` (a list of { id, name, color, deleted }): the exact name first, then its
 * other spellings in order. Deleted labels never count. Null when the account has none.
 */
function findLabel(labels, wanted) {
  const live = (labels ?? []).filter((l) => l && !l.deleted && typeof l.name === 'string');

  for (const name of namesFor(wanted)) {
    const found = live.find((l) => normalize(l.name) === name);
    if (found) return found;
  }

  return null;
}

/**
 * Whether the primary phone runs WhatsApp Business: its platform is "smba" (Android) or "smbi" (iOS). Null when the
 * login does not say (then labels decide).
 */
function isBusiness(platform) {
  if (typeof platform !== 'string' || platform === '') return null;

  return /^smb/i.test(platform);
}

/** A label as kept and as the CRM sees it. */
function clean(label) {
  return { id: String(label.id), name: String(label.name ?? ''), color: Number.isInteger(label.color) ? label.color : null, deleted: label.deleted === true };
}

module.exports = { findLabel, namesFor, isBusiness, normalize, clean };
