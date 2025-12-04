(function(){
  if (!window.CO360Analytics) return;
  const send = (payload) => fetch(CO360Analytics.restUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type':'application/json', 'X-WP-Nonce': CO360Analytics.nonce },
    body: JSON.stringify(Object.assign({ source:'frontend' }, payload))
  }).then(r=>r.json().catch(()=>({}))).then(res=>{ if (window.console) console.log('[CO360 track]', payload, res); return res; })
    .catch(err=>{ if (window.console) console.error('[CO360 track error]', err); });

  document.addEventListener('click', function(e){
    const el = e.target.closest('[data-co360-action]');
    if (!el) return;
    const action = el.getAttribute('data-co360-action');
    const postId = parseInt(el.getAttribute('data-co360-post')||'0',10)||0;
    send({ action: action, post_id: postId });
  });

  window.CO360Track = {
    viewSlideKit:  function(postId, extra){ return send({ action:'view_slidekit',  post_id: postId, meta: extra||{} }); },
    viewHighlights:function(postId, extra){ return send({ action:'view_highlights', post_id: postId, meta: extra||{} }); }
  };
})();