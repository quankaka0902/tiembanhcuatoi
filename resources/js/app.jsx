import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

createInertiaApp({
    async resolve(name) {
        if (name.startsWith('Admin')) return (await import('./admin'))[name];
        if (['Cart', 'Checkout', 'Tracking', 'Order', 'History', 'OrderPolicy'].includes(name)) return (await import('./checkout'))[name];
        return (await import('./storefront'))[name];
    },
    title: title => title || 'Tiệm Bánh Nhà Mình',
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#b92955', showSpinner: true },
});
