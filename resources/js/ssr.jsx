import { createInertiaApp } from '@inertiajs/react';
import { renderToString } from 'react-dom/server';
import { createServer } from 'node:http';
import process from 'node:process';
import { Buffer } from 'node:buffer';
import { RenderPage } from './seo-head';

const publicComponents = ['Home', 'Catalog', 'Product', 'OrderPolicy', 'PublicInfo'];

async function render(page) {
    if (!publicComponents.includes(page.component) || page.props?.auth || Number(page.props?.cartCount || 0) !== 0 || page.props?.notice || Object.keys(page.props?.errors || {}).length) throw new Error('Public anonymous pages only');
    return createInertiaApp({
        page,
        render: renderToString,
        title: value => value || 'Tiệm Bánh Nhà Mình',
        resolve: async name => {
            if (!publicComponents.includes(name)) throw new Error('Unsupported public page');
            return ['PublicInfo', 'OrderPolicy'].includes(name) ? (await import('./public-info'))[name] : (await import('./storefront'))[name];
        },
        setup: ({ App, props }) => <App {...props}>{({ Component, key, props: pageProps }) => <RenderPage Component={Component} componentKey={key} pageProps={pageProps} />}</App>,
    });
}

async function smoke() {
    const base = { url: '/', version: null, clearHistory: false, encryptHistory: false, props: { shop: { name: 'Tiệm kiểm thử SSR', accepting_orders: false, pause_message: 'Dữ liệu thử', address: '', hours: '', phone: '', email: '' }, auth: null, cartCount: 0, notice: null, errors: [], seo: { title: 'Trang kiểm thử', description: 'Dữ liệu kiểm thử render', robots: 'noindex, nofollow', canonical: null, jsonLd: [] } } };
    const pages = [
        ['Home', { categories: [], featured: [], occasions: [] }],
        ['Catalog', { categories: [], products: { data: [], total: 0, last_page: 1 }, filters: {}, filterOptions: { occasions: [], flavors: [] } }],
        ['Product', { product: { id: 1, name: 'Bánh kiểm thử SSR', slug: 'banh-kiem-thu', description: 'Dữ liệu thử', category: 'Kiểm thử', images: [], variants: [], accessories: [], prep_minutes: 60, customizable: false, ingredients: null, allergens: null, storage_instructions: null }, suggestedVariantId: null, selectionFilters: {} }],
        ['OrderPolicy', { policy: null, privacy: null }],
        ['PublicInfo', { info: { kind: 'about', title: 'Giới thiệu kiểm thử', approved: false, intro: null, store: {}, faqs: [] } }],
    ];
    for (const [component, props] of pages) {
        const output = await render({ ...base, component, props: { ...base.props, ...props } });
        if (!output.body.includes('id="app"') || !output.body.includes('<h1') || !output.head.some(tag => tag.includes('noindex, nofollow'))) throw new Error('Public SSR smoke failed');
    }
    let refused = false;
    try { await render({ ...base, component: 'Order', props: base.props }); } catch { refused = true; }
    if (!refused) throw new Error('Private SSR guard failed');
    process.stdout.write('SSR smoke passed for five public pages; private pages refused.\n');
}

if (process.argv.includes('--smoke')) {
    await smoke();
} else {
    const port = Number(process.env.BAKERY_SSR_PORT || 13714);
    if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('Invalid loopback SSR port');
    createServer(async (request, response) => {
        response.setHeader('Content-Type', 'application/json');
        response.setHeader('Cache-Control', 'no-store');
        if (request.method === 'GET' && request.url === '/health') {
            response.end(JSON.stringify({ status: 'OK' }));
            return;
        }
        if (request.method !== 'POST' || request.url !== '/render') {
            response.statusCode = 404;
            response.end(JSON.stringify({ error: 'Not found' }));
            return;
        }
        try {
            const chunks = [];
            let bytes = 0;
            for await (const chunk of request) {
                bytes += chunk.length;
                if (bytes > 1048576) throw new Error('SSR request too large');
                chunks.push(chunk);
            }
            response.end(JSON.stringify(await render(JSON.parse(Buffer.concat(chunks).toString('utf8')))));
        } catch {
            // Never log page payloads, private URLs or data from a failed request.
            response.statusCode = 503;
            response.end(JSON.stringify({ error: 'Public render unavailable' }));
        }
    }).listen(port, '127.0.0.1', () => process.stdout.write(`Public SSR listening on loopback port ${port}.\n`));
}
