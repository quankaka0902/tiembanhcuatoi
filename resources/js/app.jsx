import '../css/app.css';
import '../css/storefront-mobile.css';
import '../css/checkout-accessibility.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot, hydrateRoot } from 'react-dom/client';
import { RenderPage } from './seo-head';

createInertiaApp({
    async resolve(name) {
        if (name.startsWith('Admin')) return (await import('./admin'))[name];
        if (['PublicInfo', 'OrderPolicy'].includes(name)) return (await import('./public-info'))[name];
        if (['Cart', 'Checkout', 'Tracking', 'Order', 'OrderAction', 'History'].includes(name)) return (await import('./checkout'))[name];
        return (await import('./storefront'))[name];
    },
    title: title => title || 'Tiệm Bánh Nhà Mình',
    setup({ el, App, props }) {
        const application = <App {...props}>{({ Component, key, props: pageProps }) => <RenderPage Component={Component} componentKey={key} pageProps={pageProps} />}</App>;
        if (el.dataset.reactSsr === 'true') hydrateRoot(el, application);
        else {
            el.replaceChildren();
            createRoot(el).render(application);
        }
    },
    progress: { color: '#b92955', showSpinner: true },
});
