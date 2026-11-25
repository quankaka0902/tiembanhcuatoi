/* Local benchmark instrumentation only; no telemetry, storage or user interactions. */
(() => {
  const tag = document.createElement('meta');
  tag.name = 'bakery-lab-metrics';
  document.head.append(tag);
  let lcp = null, cls = 0;
  const observe = (type, callback) => {
    try { new PerformanceObserver(list => list.getEntries().forEach(callback)).observe({type, buffered:true}); } catch { /* unsupported browser is reported by null */ }
  };
  observe('largest-contentful-paint', entry => { lcp = {ms:entry.startTime,size:entry.size,url:entry.url ? new URL(entry.url).pathname : null}; });
  observe('layout-shift', entry => { if(!entry.hadRecentInput) cls += entry.value; });
  const emit = () => {
    const nav=performance.getEntriesByType('navigation')[0];
    const resources=performance.getEntriesByType('resource');
    const sums={js:0,css:0,image:0,font:0,other:0};
    let lastEnd=nav?.responseEnd||0;
    for(const item of resources){
      const path=new URL(item.name,location.href).pathname;
      const kind=path==='/__perf/probe.js'?'other':/\.m?js$/.test(path)?'js':/\.css$/.test(path)?'css':/\.(jpg|jpeg|webp|png|svg)$/.test(path)?'image':/\.woff2?$/.test(path)?'font':'other';
      sums[kind]+=item.encodedBodySize;
      lastEnd=Math.max(lastEnd,item.responseEnd);
    }
    tag.content=JSON.stringify({viewport:innerWidth,viewport_height:innerHeight,client_width:document.documentElement.clientWidth,ttfb_ms:nav?.responseStart??null,window_load_ms:nav?.loadEventEnd??null,fcp_ms:performance.getEntriesByName('first-contentful-paint')[0]?.startTime??null,lcp,cls,paint_entry_types:PerformanceObserver.supportedEntryTypes.filter(type=>['paint','largest-contentful-paint','layout-shift'].includes(type)),html_encoded_bytes:nav?.encodedBodySize??null,resource_encoded_bytes:sums,resource_transfer_bytes:resources.reduce((sum,item)=>sum+item.transferSize,0),request_count:resources.length,quiet:document.readyState==='complete'&&performance.now()-lastEnd>1800,images:Array.from(document.images).map(img=>({src:new URL(img.currentSrc||img.src,location.href).pathname,natural_width:img.naturalWidth,display_width:Math.round(img.getBoundingClientRect().width),width_attribute:img.getAttribute('width'),height_attribute:img.getAttribute('height'),loading:img.loading,priority:img.fetchPriority})),document_width:document.documentElement.scrollWidth});
  };
  setInterval(emit,300);
  addEventListener('load',emit,{once:true});
})();
