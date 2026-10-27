/**
 * Small HRM helpers for the screens (tested in tests/js/hrm.test.js). The
 * server decides again; these give instant feedback while typing.
 */

/** Badge tone of an employment status. */
export function statusTone(status) {
    return { probation: 'warn', active: 'ok', on_notice: 'brand', exited: 'neutral' }[status] ?? 'neutral';
}

/** Initials for the avatar: first letters of the first two words. */
export function initials(name) {
    return (name ?? '')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => Array.from(word)[0] ?? '')
        .join('')
        .toUpperCase();
}

/** Details the rules require that are still empty in the form. */
export function missingRequired(form, required) {
    return (required ?? []).filter((field) => {
        const value = form[field];
        if (value === null || value === undefined) return true;
        if (typeof value === 'string') return value.trim() === '';
        if (typeof value === 'object') return Object.values(value).every((part) => !part || String(part).trim() === '');
        return false;
    });
}

/** "2026-10-01" + 90 days -> "2026-12-30" (calendar days, in UTC like the server). */
export function addDays(date, days) {
    if (!date || !Number.isFinite(days)) return null;
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + days);
    return value.toISOString().slice(0, 10);
}

/**
 * The hire request body: empty optional details are left out, empty
 * address / emergency contact parts too.
 */
export function hirePayload(form) {
    const body = {};
    for (const [key, value] of Object.entries(form)) {
        if (value === null || value === undefined) continue;
        if (typeof value === 'string' && value.trim() === '') continue;
        if (key === 'custom') {
            const custom = customPayload(value);
            if (Object.keys(custom).length) body.custom = custom;
            continue;
        }
        if (typeof value === 'object') {
            const parts = Object.fromEntries(Object.entries(value).filter(([, part]) => part && String(part).trim() !== ''));
            if (Object.keys(parts).length) body[key] = parts;
            continue;
        }
        body[key] = typeof value === 'string' ? value.trim() : value;
    }
    return body;
}

/** Only the details that changed, for a PATCH with the version seen. */
export function changedDetails(original, form) {
    const changes = {};
    for (const [key, value] of Object.entries(form)) {
        const before = original[key] ?? null;
        const after = typeof value === 'string' ? value.trim() || null : value;
        if (JSON.stringify(before) !== JSON.stringify(after)) changes[key] = after;
    }
    return changes;
}

const isEmpty = (value) => value === null || value === undefined || (typeof value === 'string' && value.trim() === '');

/** Extra field values to send: empty ones left out, but "no" (false) and 0 kept. */
export function customPayload(values) {
    return Object.fromEntries(
        Object.entries(values ?? {})
            .filter(([, value]) => !isEmpty(value))
            .map(([key, value]) => [key, typeof value === 'string' ? value.trim() : value]),
    );
}

/** Extra fields that changed; a cleared one is sent as null (the server merges). */
export function changedCustom(before, after) {
    const changes = {};
    const keys = new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})]);
    for (const key of keys) {
        const old = isEmpty(before?.[key]) ? null : before[key];
        const value = isEmpty(after?.[key]) ? null : typeof after[key] === 'string' ? after[key].trim() : after[key];
        if (old !== value) changes[key] = value;
    }
    return changes;
}

/** Required extra fields still empty. */
export function missingCustom(fields, values) {
    return (fields ?? []).filter((field) => field.is_required && isEmpty(values?.[field.key]));
}

/**
 * A field key suggested from its English label: "Blood group" -> "blood_group"
 * (lower case letters, digits and _, starting with a letter, at most 40).
 */
export function fieldKeyFrom(label) {
    const key = (label ?? '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^[^a-z]+/, '')
        .replace(/_+$/, '')
        .slice(0, 40)
        .replace(/_+$/, '');
    return key.length >= 2 ? key : '';
}

/**
 * The import template: one header line with the column names. UTF-8 with a
 * byte order mark, so Excel keeps Bangla text readable.
 */
export function csvTemplate(columns) {
    return `﻿${(columns ?? []).map((column) => column.key).join(',')}\r\n`;
}

/** Which steps can be taken now, and which permission each needs. */
export function availableSteps(employee, can) {
    if (!employee) return [];
    const status = employee.status;
    const steps = [];
    if (can.manage && status === 'probation') steps.push('confirm');
    if (can.manage && status !== 'exited') steps.push('transfer', 'promote');
    if (can.exit && ['probation', 'active'].includes(status)) steps.push('notice');
    if (can.exit && status !== 'exited') steps.push('exit');
    if (can.manage && status === 'exited') steps.push('rehire');
    return steps;
}

const PLACE_TYPES = ['company', 'branch', 'personal'];

/** Open branches and companies people can work at, in tree order (as /api/organizations lists them). */
export function workPlaces(units) {
    return units.filter((unit) => PLACE_TYPES.includes(unit.type) && unit.status === 'active');
}

/** The open departments right under a branch (or the company). */
export function departmentsOf(units, placeId) {
    if (!placeId) return [];
    return units.filter((unit) => unit.type === 'department' && unit.parent_id === placeId && unit.status === 'active');
}

/** The branch (or company) of a unit: a department's parent, else the unit itself; '' when none. */
export function placeOf(units, unitId) {
    const unit = units.find((item) => item.id === unitId);
    if (!unit) return '';
    return unit.type === 'department' && unit.parent_id ? unit.parent_id : unit.id;
}

/** Today as "YYYY-MM-DD" by the reader's clock (UTC is yesterday before 6 am in Dhaka). */
export function today(now = new Date()) {
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}
