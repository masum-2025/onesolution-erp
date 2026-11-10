/**
 * Barcodes drawn as modules ('1' = bar, '0' = space): EAN-13 when the code
 * is a valid one, else Code 128. Shared by every module that prints labels
 * (shop shelves, lab sample tubes).
 */

const EAN_L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
const EAN_G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
const EAN_PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

/** Whether thirteen digits carry a right EAN-13 check digit. */
export function ean13Valid(code) {
    if (!/^\d{13}$/.test(code)) return false;
    const sum = [...code.slice(0, 12)].reduce((total, digit, index) => total + Number(digit) * (index % 2 === 0 ? 1 : 3), 0);
    return (10 - (sum % 10)) % 10 === Number(code[12]);
}

/** EAN-13 as 95 modules ("1" bar, "0" space). */
export function ean13Modules(code) {
    const parity = EAN_PARITY[Number(code[0])];
    let modules = '101';
    for (let index = 1; index <= 6; index++) modules += (parity[index - 1] === 'L' ? EAN_L : EAN_G)[Number(code[index])];
    modules += '01010';
    for (let index = 7; index <= 12; index++) modules += [...EAN_L[Number(code[index])]].map((bit) => (bit === '1' ? '0' : '1')).join('');
    return `${modules}101`;
}

const CODE128 = [
    '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
    '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
    '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313', '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
    '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
    '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
    '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
    '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
];
export const CODE128_TABLE = CODE128;

/** Code 128 set B as modules; null when a character is outside printable ASCII. */
export function code128Modules(text) {
    const values = [...text].map((char) => char.charCodeAt(0) - 32);
    if (!values.length || values.some((value) => value < 0 || value > 94)) return null;
    const check = values.reduce((sum, value, index) => sum + value * (index + 1), 104) % 103;
    return [104, ...values, check, 106].map((value) => [...CODE128[value]].map((width, index) => (index % 2 === 0 ? '1' : '0').repeat(Number(width))).join('')).join('');
}

/** The modules of an item's barcode (EAN-13 when valid, else Code 128 of the barcode or SKU). */
export function barcodeModules(code) {
    return ean13Valid(code) ? ean13Modules(code) : code128Modules(code);
}
