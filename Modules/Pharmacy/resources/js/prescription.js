import { latinDigits } from '@/lib/numbers';
import { packsOf, piecesIn } from './api';

/**
 * How much of a prescribed medicine to suggest at the counter, from the
 * doctor's dose ("1+0+1", "১+০+১", "½+0+½") and duration ("7 days", "৭ দিন",
 * "2 weeks", "১ মাস"). Only a suggestion: anything not a plain count gives
 * null, and the seller always changes it freely.
 */
const latin = (text) => latinDigits(String(text ?? ''));

/** Pieces a day from a dose like "1+0+1"; null when it is not a count. */
export function dosePerDay(dose) {
    const text = latin(dose).replace(/½/g, '0.5').replace(/\s+/g, '');
    if (!/^\d+(\.\d+)?([+-]\d+(\.\d+)?){1,5}$/.test(text)) return null;
    const total = text.split(/[+-]/).reduce((sum, part) => sum + Number(part), 0);
    return total > 0 ? total : null;
}

/** Days from a duration like "7 days", "২ সপ্তাহ" or "1 month"; null when it has no number. */
export function durationDays(duration) {
    const match = latin(duration).toLowerCase().match(/(\d+)\s*(days?|d|দিন|weeks?|w|সপ্তাহ|months?|মাস)?/u);
    if (!match) return null;
    const count = Number(match[1]);
    const unit = match[2] ?? 'day';
    if (count <= 0) return null;
    if (/^(weeks?|w|সপ্তাহ)$/u.test(unit)) return count * 7;
    if (/^(months?|মাস)$/u.test(unit)) return count * 30;
    return count;
}

/** Whole pieces for the whole course; null when the dose or the duration is not plain. */
export function suggestedPieces(dose, duration) {
    const perDay = dosePerDay(dose);
    const days = durationDays(duration);
    return perDay && days ? Math.ceil(perDay * days) : null;
}

/**
 * The pack and quantity to put in the cart for these pieces: pieces where
 * loose pieces are sold (strips when it is whole strips), otherwise whole
 * strips or boxes rounded up; one of the usual pack without a suggestion.
 */
export function suggestedLine(medicine, loosePieces, pieces) {
    const packs = packsOf(medicine, loosePieces);
    if (!packs.length) return null;
    if (!pieces) return { pack: packs.includes('strip') ? 'strip' : packs[0], quantity: 1 };
    const strip = packs.includes('strip') ? piecesIn(medicine, 'strip') : null;
    if (strip && pieces % strip === 0) return { pack: 'strip', quantity: pieces / strip };
    if (packs.includes('piece')) return { pack: 'piece', quantity: pieces };
    const pack = strip ? 'strip' : packs[0];
    return { pack, quantity: Math.max(1, Math.ceil(pieces / (piecesIn(medicine, pack) || 1))) };
}
