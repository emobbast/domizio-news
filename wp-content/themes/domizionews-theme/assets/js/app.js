/* Domizio News App — standalone bundle (UMD, no build required)
   Carica React da CDN tramite importmap nel template. */

(function () {

  // ─── CONFIG: legge da window.DNAPP_CONFIG iniettato da WordPress ────────────
  const CFG = window.DNAPP_CONFIG || {};
  const API = CFG.wpBase ? CFG.wpBase.replace(/\/$/, '') : '';
  const CUSTOM_API = API.replace('/wp/v2', '') + '/dnapp/v1';

  // ─── UTILS ──────────────────────────────────────────────────────────────────
  function timeAgo(date) {
    const diff = Date.now() - new Date(date).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 2) return 'Ora';
    if (mins < 60) return mins + 'm fa';
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return hrs + 'h fa';
    return Math.floor(hrs / 24) + 'g fa';
  }

  function stripHtml(html) {
    const d = document.createElement('div');
    d.innerHTML = html || '';
    return d.textContent || '';
  }

  // ─── STATE ──────────────────────────────────────────────────────────────────
  let state = {
    tab: 'home',
    selectedPost: null,
    posts: [],
    cities: [],
    categories: [],
    loading: true,
    searchQuery: '',
    selectedCity: '',
    selectedCat: '',
  };

  function setState(patch) {
    state = Object.assign({}, state, patch);
    render();
  }

  // ─── API ────────────────────────────────────────────────────────────────────
  async function loadData() {
    try {
      const [feedRes, configRes] = await Promise.all([
        fetch(CUSTOM_API + '/feed?per_page=20'),
        fetch(CUSTOM_API + '/config'),
      ]);
      const feed   = await feedRes.json();
      const config = await configRes.json();
      setState({
        posts:      feed.posts || [],
        cities:     config.cities || [],
        categories: config.categories || [],
        loading:    false,
      });
    } catch (e) {
      console.error('Errore API:', e);
      setState({ loading: false });
    }
  }

  // ─── ICONS (SVG string) ──────────────────────────────────────────────────────
  const ICO = {
    home:    `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>`,
    cities:  `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>`,
    cats:    `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>`,
    search:  `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>`,
    back:    `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>`,
    share:   `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>`,
    bell:    `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>`,
  };

  const CAT_ICONS = { 'cronaca': '🚨', 'sport': '⚽', 'politica': '🏛️', 'ambiente-mare': '🌊', 'eventi-cultura': '🎭', 'salute': '🏥' };

  // ─── AD SLOTS ────────────────────────────────────────────────────────────────
  function buildNativeAd(num) {
    return `
      <div class="dn-ad-slot" data-slot="${num}" style="
        background:#f5f2ef;border:1.5px solid #e8a87c;border-radius:10px;
        height:90px;display:flex;flex-direction:column;align-items:center;
        justify-content:center;margin:8px 0;gap:4px;
      ">
        <span style="font-size:9px;color:#bbb;letter-spacing:1.5px;text-transform:uppercase;font-weight:700;">SPONSORIZZATO</span>
        <span style="font-size:13px;color:#999;">Pubblicità</span>
      </div>`;
  }

  function buildCityAd() {
    return `
      <div class="dn-ad-slot" data-slot="city-ad" style="
        background:#f5f2ef;border:1.5px solid #e8a87c;border-radius:10px;
        height:90px;display:flex;flex-direction:column;align-items:center;
        justify-content:center;margin:8px 20px;gap:4px;
      ">
        <span style="font-size:9px;color:#bbb;letter-spacing:1.5px;text-transform:uppercase;font-weight:700;">SPONSORIZZATO</span>
        <span style="font-size:13px;color:#999;">Pubblicità</span>
      </div>`;
  }

  // ─── HTML BUILDERS ──────────────────────────────────────────────────────────
  function buildArticleCard(post, featured = false) {
    const img  = post.image || '';
    const cat  = post.categories?.[0];
    const city = post.cities?.[0];

    if (featured) {
      return `
        <div class="dn-card-featured" data-post-id="${post.id}">
          ${img ? `<img src="${img}" alt="" loading="lazy">` : ''}
          <div class="dn-card-featured-overlay">
            ${cat ? `<span class="dn-cat-badge">${cat.name}</span>` : ''}
            <h3>${post.title}</h3>
            <span class="dn-time">${timeAgo(post.date)}</span>
          </div>
        </div>`;
    }

    return `
      <div class="dn-card-list" data-post-id="${post.id}">
        ${img ? `<img src="${img}" alt="" loading="lazy">` : ''}
        <div class="dn-card-body">
          <div class="dn-card-meta">
            ${cat  ? `<span class="dn-cat-label">${cat.name}</span>` : ''}
            ${city ? `<span class="dn-city-label">📍 ${city.name}</span>` : ''}
          </div>
          <h3>${post.title}</h3>
          <span class="dn-time">${timeAgo(post.date)}</span>
        </div>
      </div>`;
  }

  function buildHome() {
    const featured = state.posts.slice(0, 3);

    let citySections = '';
    let cityCount = 0;
    state.cities.forEach(city => {
      const cityPosts = state.posts.filter(p => p.cities?.some(c => c.slug === city.slug));
      if (cityPosts.length === 0) return;
      cityCount++;
      if (cityCount > 1 && (cityCount - 1) % 2 === 0) {
        citySections += buildCityAd();
      }
      const shown = cityPosts.slice(0, 3);
      citySections += `
        <div class="dn-section-label" style="margin: 20px 20px 8px">📍 ${city.name}</div>
        <div class="dn-list" style="padding: 0 20px">
          ${shown.map(p => buildArticleCard(p)).join('')}
        </div>
        <div style="padding: 4px 20px 12px; text-align: right">
          <button class="dn-city-more" data-goto-city="${city.slug}">→ Vedi tutte le notizie di ${city.name}</button>
        </div>`;
    });

    return `
      <div class="dn-screen" id="screen-home">
        <div class="dn-top-header">
          <div>
            <div class="dn-kicker">IL GIORNALE DEL</div>
            <h1 class="dn-site-title">Litorale Domizio</h1>
          </div>
          <button class="dn-icon-btn">${ICO.bell}<span class="dn-notif-dot"></span></button>
        </div>
        <div class="dn-section-label">🔥 In evidenza</div>
        <div class="dn-featured-scroll">
          ${featured.map(p => buildArticleCard(p, true)).join('')}
        </div>
        ${citySections}
      </div>`;
  }

  function buildCities() {
    const filtered = state.selectedCity
      ? state.posts.filter(p => p.cities?.some(c => c.slug === state.selectedCity))
      : state.posts;
    return `
      <div class="dn-screen">
        <div class="dn-page-header"><h2>Città</h2></div>
        <div class="dn-chips-scroll">
          ${state.cities.map(c => `
            <button class="dn-chip ${state.selectedCity === c.slug ? 'active' : ''}" data-city="${c.slug}">${c.name}</button>
          `).join('')}
        </div>
        <div class="dn-list" style="padding: 0 20px">
          ${filtered.length === 0
            ? `<p class="dn-empty">Nessun articolo per questa città.</p>`
            : filtered.map(p => buildArticleCard(p)).join('')}
        </div>
      </div>`;
  }

  function buildCategories() {
    const filtered = state.selectedCat
      ? state.posts.filter(p => p.categories?.some(c => c.slug === state.selectedCat))
      : state.posts;
    return `
      <div class="dn-screen">
        <div class="dn-page-header"><h2>Categorie</h2></div>
        <div class="dn-cat-grid">
          ${state.categories.map(c => `
            <button class="dn-cat-tile ${state.selectedCat === c.slug ? 'active' : ''}" data-cat="${c.slug}">
              <span class="dn-cat-icon">${CAT_ICONS[c.slug] || '📰'}</span>
              ${c.name}
            </button>
          `).join('')}
        </div>
        <div class="dn-list" style="padding: 0 20px">
          ${filtered.map(p => buildArticleCard(p)).join('')}
        </div>
      </div>`;
  }

  function buildSearch() {
    const q        = state.searchQuery;
    const filtered = q.length > 1
      ? state.posts.filter(p =>
          p.title.toLowerCase().includes(q.toLowerCase()) ||
          p.excerpt.toLowerCase().includes(q.toLowerCase()))
      : [];
    return `
      <div class="dn-screen">
        <div class="dn-page-header"><h2>Cerca</h2></div>
        <div style="padding: 0 20px 16px">
          <div class="dn-search-wrap">
            <span class="dn-search-icon">${ICO.search}</span>
            <input id="dn-search-input" type="search" placeholder="Cerca notizie..." value="${q}" autocomplete="off">
          </div>
        </div>
        <div class="dn-list" style="padding: 0 20px">
          ${q.length < 2
            ? `<p class="dn-empty" style="margin-top:60px">Digita almeno 2 caratteri</p>`
            : filtered.length === 0
              ? `<p class="dn-empty" style="margin-top:60px">Nessun risultato per "<b>${q}</b>"</p>`
              : `<p style="font-size:13px;color:#aaa;margin-bottom:8px">${filtered.length} risultati</p>
                 ${filtered.map(p => buildArticleCard(p)).join('')}`}
        </div>
      </div>`;
  }

  function buildArticleDetail(post) {
    const cat  = post.categories?.[0];
    const date = new Date(post.date).toLocaleDateString('it-IT', { day: 'numeric', month: 'long', year: 'numeric' });
    return `
      <div class="dn-detail">
        <div class="dn-detail-header">
          <button class="dn-back-btn" id="dn-back">${ICO.back} Indietro</button>
          <button class="dn-icon-btn">${ICO.share}</button>
        </div>
        ${post.image ? `
          <div class="dn-detail-img-wrap">
            <img src="${post.image}" alt="">
            <div class="dn-detail-img-fade"></div>
          </div>` : ''}
        <div class="dn-detail-body">
          <div class="dn-badges">
            ${post.categories?.map(c => `<span class="dn-badge-cat">${c.name}</span>`).join('') || ''}
            ${post.cities?.map(c => `<span class="dn-badge-city">📍 ${c.name}</span>`).join('') || ''}
          </div>
          <h1 class="dn-detail-title">${post.title}</h1>
          <div class="dn-detail-byline">
            <div class="dn-avatar">R</div>
            <div>
              <div class="dn-byline-name">Redazione</div>
              <div class="dn-byline-date">${date}</div>
            </div>
          </div>
          <div class="dn-detail-content">${post.content}</div>
          <div class="dn-ad-slot" data-slot="3" style="width:100%;height:100px;background:#f5f2ef;border:1.5px solid #e8a87c;display:flex;align-items:center;justify-content:center;margin:20px 0;box-sizing:border-box;">
            <span style="font-size:13px;color:#999;">Pubblicità</span>
          </div>
        </div>
      </div>`;
  }

  function buildNav() {
    const tabs = [
      { id: 'home',       label: 'Home',      icon: ICO.home },
      { id: 'cities',     label: 'Città',     icon: ICO.cities },
      { id: 'categories', label: 'Categorie', icon: ICO.cats },
      { id: 'search',     label: 'Cerca',     icon: ICO.search },
    ];
    return `
      <div class="dn-ad-slot dn-ad-sticky" data-slot="4">
        <span style="color:#fff;font-size:13px;">Pubblicità</span>
      </div>
      <nav class="dn-bottom-nav">
        ${tabs.map(t => `
          <button class="dn-nav-tab ${state.tab === t.id ? 'active' : ''}" data-tab="${t.id}">
            ${t.icon}
            <span>${t.label}</span>
            ${state.tab === t.id ? '<span class="dn-nav-dot"></span>' : ''}
          </button>
        `).join('')}
      </nav>`;
  }

  function buildLoading() {
    return `
      <div class="dn-loading">
        <div class="dn-loading-wave">🌊</div>
        <h2>Litorale Domizio</h2>
        <p>Caricamento notizie...</p>
      </div>`;
  }

  // ─── STYLES ─────────────────────────────────────────────────────────────────
  const STYLES = `
    @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800;900&display=swap');

    .dn-app { font-family: 'Segoe UI', system-ui, sans-serif; background: #faf9f7; min-height: 100vh; padding-bottom: 130px; }

    /* LOADING */
    .dn-loading { height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #1a1a2e; color: #fff; gap: 8px; }
    .dn-loading-wave { font-size: 48px; animation: float 2s ease-in-out infinite; }
    .dn-loading h2 { font-family: 'Playfair Display', Georgia, serif; font-size: 26px; margin: 0; }
    .dn-loading p { color: #e8a87c; font-size: 12px; letter-spacing: 2px; text-transform: uppercase; margin: 0; }
    @keyframes float { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }

    /* TOP HEADER */
    .dn-top-header { padding: 20px 20px 16px; display: flex; align-items: center; justify-content: space-between; }
    .dn-kicker { font-size: 11px; font-weight: 700; letter-spacing: 3px; text-transform: uppercase; color: #b5541a; margin-bottom: 2px; }
    .dn-site-title { margin: 0; font-size: 26px; font-weight: 900; color: #1a1a2e; font-family: 'Playfair Display', Georgia, serif; line-height: 1; }
    .dn-icon-btn { background: none; border: none; cursor: pointer; color: #1a1a2e; position: relative; padding: 4px; }
    .dn-notif-dot { position: absolute; top: 2px; right: 2px; width: 8px; height: 8px; background: #b5541a; border-radius: 50%; display: block; }

    /* SECTION LABELS */
    .dn-section-label { font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: #aaa; padding-left: 20px; margin-bottom: 12px; }
    .dn-page-header { padding: 20px 20px 0; }
    .dn-page-header h2 { margin: 0 0 16px; font-size: 22px; font-weight: 800; color: #1a1a2e; font-family: 'Playfair Display', Georgia, serif; }

    /* FEATURED CARDS */
    .dn-featured-scroll { display: flex; gap: 12px; overflow-x: auto; padding: 0 20px 4px; scroll-snap-type: x mandatory; }
    .dn-card-featured { position: relative; border-radius: 16px; overflow: hidden; cursor: pointer; flex-shrink: 0; width: 280px; height: 200px; background: #1a1a2e; box-shadow: 0 8px 32px rgba(0,0,0,0.15); scroll-snap-align: start; transition: transform 0.15s; }
    .dn-card-featured:active { transform: scale(0.97); }
    .dn-card-featured img { width: 100%; height: 100%; object-fit: cover; opacity: 0.7; }
    .dn-card-featured-overlay { position: absolute; inset: 0; background: linear-gradient(to top, rgba(0,0,0,0.85) 0%, transparent 55%); padding: 16px; display: flex; flex-direction: column; justify-content: flex-end; }
    .dn-card-featured h3 { margin: 0; font-size: 15px; font-weight: 700; color: #fff; font-family: 'Playfair Display', Georgia, serif; line-height: 1.3; }
    .dn-cat-badge { font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: #e8a87c; margin-bottom: 6px; display: block; }

    /* LIST CARDS */
    .dn-card-list { display: flex; gap: 12px; padding: 14px 0; border-bottom: 1px solid #f0ece8; cursor: pointer; align-items: flex-start; transition: opacity 0.15s; }
    .dn-card-list:active { opacity: 0.7; }
    .dn-card-list img { width: 90px; height: 68px; object-fit: cover; border-radius: 10px; flex-shrink: 0; }
    .dn-card-body { flex: 1; min-width: 0; }
    .dn-card-meta { display: flex; gap: 6px; margin-bottom: 5px; flex-wrap: wrap; align-items: center; }
    .dn-cat-label { font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: #b5541a; }
    .dn-city-label { font-size: 10px; color: #999; }
    .dn-card-body h3 { margin: 0; font-size: 15px; font-weight: 700; color: #1a1a2e; font-family: 'Playfair Display', Georgia, serif; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .dn-time { font-size: 11px; color: #aaa; margin-top: 4px; display: block; }

    /* CHIPS */
    .dn-chips-scroll { display: flex; gap: 8px; overflow-x: auto; padding: 0 20px 16px; }
    .dn-chip { flex-shrink: 0; padding: 7px 16px; border-radius: 20px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; background: #f0ece8; color: #555; transition: all 0.2s; font-family: inherit; }
    .dn-chip.active { background: #1a1a2e; color: #fff; }

    /* CATEGORY GRID */
    .dn-cat-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; padding: 0 20px 20px; }
    .dn-cat-tile { padding: 14px 8px; border-radius: 12px; border: 2px solid #ede9e4; cursor: pointer; font-size: 12px; font-weight: 700; line-height: 1.3; background: #faf9f7; color: #333; transition: all 0.2s; font-family: inherit; }
    .dn-cat-tile.active { background: #1a1a2e; border-color: #1a1a2e; color: #fff; }
    .dn-cat-icon { display: block; font-size: 20px; margin-bottom: 4px; }

    /* SEARCH */
    .dn-search-wrap { position: relative; }
    .dn-search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #aaa; display: flex; }
    #dn-search-input { width: 100%; padding: 12px 14px 12px 46px; border-radius: 12px; border: 2px solid #ede9e4; background: #f5f2ef; font-size: 16px; outline: none; font-family: inherit; box-sizing: border-box; }
    #dn-search-input:focus { border-color: #b5541a; }

    /* EMPTY */
    .dn-empty { color: #bbb; text-align: center; font-size: 15px; }

    /* ARTICLE DETAIL */
    .dn-detail { min-height: 100vh; background: #faf9f7; padding-bottom: 130px; }
    .dn-detail-header { position: sticky; top: 0; z-index: 10; background: rgba(250,249,247,0.95); backdrop-filter: blur(12px); padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #ede9e4; }
    .dn-back-btn { background: none; border: none; cursor: pointer; color: #1a1a2e; display: flex; align-items: center; gap: 4px; font-size: 15px; font-weight: 600; padding: 0; font-family: inherit; }
    .dn-detail-img-wrap { position: relative; height: 240px; overflow: hidden; }
    .dn-detail-img-wrap img { width: 100%; height: 100%; object-fit: cover; }
    .dn-detail-img-fade { position: absolute; inset: 0; background: linear-gradient(to top, #faf9f7 0%, transparent 50%); }
    .dn-detail-body { padding: 0 20px; margin-top: -20px; }
    .dn-badges { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
    .dn-badge-cat { background: #b5541a; color: #fff; font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; padding: 3px 10px; border-radius: 20px; }
    .dn-badge-city { background: #f0ece8; color: #666; font-size: 11px; padding: 3px 10px; border-radius: 20px; }
    .dn-detail-title { margin: 0 0 12px; font-size: 24px; font-weight: 800; color: #1a1a2e; font-family: 'Playfair Display', Georgia, serif; line-height: 1.3; }
    .dn-detail-byline { display: flex; align-items: center; gap: 8px; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid #e8a87c; }
    .dn-avatar { width: 28px; height: 28px; border-radius: 50%; background: #1a1a2e; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; }
    .dn-byline-name { font-size: 13px; font-weight: 700; color: #1a1a2e; }
    .dn-byline-date { font-size: 11px; color: #aaa; }
    .dn-detail-content { font-size: 16px; line-height: 1.75; color: #333; font-family: Georgia, serif; }
    .dn-detail-content p { margin: 0 0 16px; }
    .dn-detail-content strong { color: #1a1a2e; }
    .dn-local-context { background: #f0f8e8; border-left: 3px solid #5a8a3c; padding: 12px; border-radius: 0 8px 8px 0; font-size: 14px !important; }

    /* CITY MORE LINK */
    .dn-city-more { background: none; border: none; cursor: pointer; color: #b5541a; font-size: 13px; font-weight: 600; font-family: inherit; padding: 0; }
    .dn-city-more:active { opacity: 0.7; }

    /* AD SLOTS */
    .dn-ad-sticky { position: fixed; bottom: 65px; left: 50%; transform: translateX(-50%); width: 100%; max-width: 430px; height: 50px; background: #1a1a2e; z-index: 99; display: flex; align-items: center; justify-content: center; }

    /* BOTTOM NAV */
    .dn-bottom-nav { position: fixed; bottom: 0; left: 50%; transform: translateX(-50%); width: 100%; max-width: 430px; background: rgba(250,249,247,0.96); backdrop-filter: blur(20px); border-top: 1px solid #ede9e4; display: flex; padding: 8px 0; padding-bottom: calc(8px + env(safe-area-inset-bottom)); z-index: 100; }
    .dn-nav-tab { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 3px; background: none; border: none; cursor: pointer; padding: 6px 0; color: #bbb; transition: color 0.2s; font-size: 10px; font-weight: 500; font-family: inherit; }
    .dn-nav-tab.active { color: #b5541a; font-weight: 700; }
    .dn-nav-dot { width: 4px; height: 4px; background: #b5541a; border-radius: 50%; display: block; margin-top: 1px; }
  `;

  // ─── RENDER ─────────────────────────────────────────────────────────────────
  function render() {
    const root = document.getElementById('domizionews-root');
    if (!root) return;

    let content = '';

    if (state.loading) {
      root.innerHTML = `<style>${STYLES}</style><div class="dn-app">${buildLoading()}</div>`;
      return;
    }

    if (state.selectedPost) {
      root.innerHTML = `<style>${STYLES}</style><div class="dn-app" style="padding-bottom:0">${buildArticleDetail(state.selectedPost)}</div>`;
      document.getElementById('dn-back')?.addEventListener('click', () => setState({ selectedPost: null }));
      return;
    }

    if (state.tab === 'home')       content = buildHome();
    if (state.tab === 'cities')     content = buildCities();
    if (state.tab === 'categories') content = buildCategories();
    if (state.tab === 'search')     content = buildSearch();

    root.innerHTML = `<style>${STYLES}</style><div class="dn-app">${content}${buildNav()}</div>`;
    attachEvents();
  }

  function attachEvents() {
    // Article cards
    document.querySelectorAll('[data-post-id]').forEach(el => {
      el.addEventListener('click', () => {
        const post = state.posts.find(p => p.id == el.dataset.postId);
        if (post) setState({ selectedPost: post });
      });
    });

    // Bottom nav
    document.querySelectorAll('[data-tab]').forEach(el => {
      el.addEventListener('click', () => setState({ tab: el.dataset.tab }));
    });

    // City chips
    document.querySelectorAll('[data-city]').forEach(el => {
      el.addEventListener('click', () => {
        const slug = el.dataset.city;
        setState({ selectedCity: state.selectedCity === slug ? '' : slug });
      });
    });

    // Category tiles
    document.querySelectorAll('[data-cat]').forEach(el => {
      el.addEventListener('click', () => {
        const slug = el.dataset.cat;
        setState({ selectedCat: state.selectedCat === slug ? '' : slug });
      });
    });

    // Home city "vedi tutte" links
    document.querySelectorAll('[data-goto-city]').forEach(el => {
      el.addEventListener('click', () => setState({ tab: 'cities', selectedCity: el.dataset.gotoCity }));
    });

    // Search input
    const searchInput = document.getElementById('dn-search-input');
    if (searchInput) {
      searchInput.addEventListener('input', (e) => {
        state.searchQuery = e.target.value;
        render(); // re-render senza ricreare lo stato
      });
      searchInput.focus();
    }
  }

  // ─── BOOT ───────────────────────────────────────────────────────────────────
  function boot() {
    render(); // mostra loading
    loadData();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

})();
