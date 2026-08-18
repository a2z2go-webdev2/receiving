export function quantity(value: number) {
    return value.toLocaleString(undefined, { maximumFractionDigits: 3 });
}

export function plain(value: string) {
    return value.replaceAll('_', ' ');
}

export function localDate() {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 10);
}
