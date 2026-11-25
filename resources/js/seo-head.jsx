import { Head } from '@inertiajs/react';

function safeJson(value) {
    return JSON.stringify(value).replace(/</g, '\\u003c').replace(/>/g, '\\u003e').replace(/&/g, '\\u0026').replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');
}

export function SeoHead({ seo }) {
    return <Head title={seo?.title}>
        <meta head-key="robots" name="robots" content={seo?.robots || 'noindex, nofollow'} />
        {seo?.description && <meta head-key="description" name="description" content={seo.description} />}
        {seo?.canonical && <link head-key="canonical" rel="canonical" href={seo.canonical} />}
        {seo?.og?.title && <meta head-key="og:title" property="og:title" content={seo.og.title} />}
        {seo?.og?.description && <meta head-key="og:description" property="og:description" content={seo.og.description} />}
        {seo?.og?.url && <meta head-key="og:url" property="og:url" content={seo.og.url} />}
        {seo?.og?.type && <meta head-key="og:type" property="og:type" content={seo.og.type} />}
        {seo?.og?.image && <meta head-key="og:image" property="og:image" content={seo.og.image} />}
        {seo?.og?.['image:width'] && <meta head-key="og:image:width" property="og:image:width" content={seo.og['image:width']} />}
        {seo?.og?.['image:height'] && <meta head-key="og:image:height" property="og:image:height" content={seo.og['image:height']} />}
        {seo?.jsonLd?.length > 0 && <script head-key="schema" type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJson(seo.jsonLd) }} />}
    </Head>;
}

export function RenderPage({ Component, componentKey, pageProps }) {
    return <><SeoHead seo={pageProps.seo} /><Component key={componentKey} {...pageProps} /></>;
}
