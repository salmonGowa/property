const root = document.getElementById('root');

export const appName = root?.dataset.appName || 'Property';

/** Base URL of the Filament back office, for screens not yet rebuilt in React. */
export const filamentUrl = root?.dataset.filamentUrl || '/admin';
