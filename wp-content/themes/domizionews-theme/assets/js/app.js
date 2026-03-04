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
    homeCat: '',
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

  // ─── AD SLOTS ────────────────────────────────────────────────────────────────
  function buildNativeAd(num) {
    return `
      <div class="dn-ad-slot" data-slot="${num}" style="
        background:#F2F2F2;border-top:1px solid #E0E0E0;border-bottom:1px solid #E0E0E0;
        height:90px;display:flex;flex-direction:column;align-items:center;
        justify-content:center;gap:4px;
      ">
        <span style="font-size:9px;color:#9AA0A6;letter-spacing:1.5px;text-transform:uppercase;font-weight:500;">SPONSORIZZATO</span>
        <span style="font-size:13px;color:#9AA0A6;">Pubblicità</span>
      </div>`;
  }

  function buildCityAd() {
    return buildNativeAd('city-ad');
  }

  // ─── CARD BADGES + METADATA ──────────────────────────────────────────────────
  function buildCardBadges(post) {
    const cat  = post.categories?.[0];
    const city = post.cities?.[0];
    if (!cat && !city) return '';
    return `
      <div class="dn-card-badges">
        ${cat  ? `<span class="dn-cat-label">${cat.name}</span>` : ''}
        ${city ? `<span class="dn-city-label">${city.name}</span>` : ''}
      </div>`;
  }

  // ─── HTML BUILDERS ──────────────────────────────────────────────────────────

  // Hero card: prima notizia del feed, immagine full-width 16/9
  function buildHeroCard(post) {
    const img = post.image || '';
    return `
      <div class="dn-card-hero" data-post-id="${post.id}">
        ${img ? `<div class="dn-card-hero-img"><img src="${img}" alt="" loading="lazy"></div>` : ''}
        <div class="dn-card-hero-body">
          <h3 class="dn-card-hero-title">${post.title}</h3>
          ${buildCardBadges(post)}
          <span class="dn-time">${timeAgo(post.date)}</span>
        </div>
      </div>`;
  }

  // List card: thumbnail 96x96 a destra
  function buildArticleCard(post) {
    const img = post.image || '';
    return `
      <div class="dn-card-list" data-post-id="${post.id}">
        <div class="dn-card-body">
          <h3>${post.title}</h3>
          ${buildCardBadges(post)}
          <span class="dn-time">${timeAgo(post.date)}</span>
        </div>
        ${img ? `<img src="${img}" alt="" loading="lazy">` : ''}
      </div>`;
  }

  // ─── CATEGORY CHIPS BAR (home) ───────────────────────────────────────────────
  const HOME_CATS = [
    { slug: '',               name: 'Tutte' },
    { slug: 'cronaca',        name: 'Cronaca' },
    { slug: 'sport',          name: 'Sport' },
    { slug: 'politica',       name: 'Politica' },
    { slug: 'economia',       name: 'Economia' },
    { slug: 'ambiente-mare',  name: 'Ambiente' },
    { slug: 'eventi-cultura', name: 'Eventi' },
    { slug: 'salute',         name: 'Salute' },
  ];

  function buildCatChipsBar() {
    return `
      <div class="dn-home-chips">
        ${HOME_CATS.map(c => `
          <button class="dn-home-chip ${state.homeCat === c.slug ? 'active' : ''}" data-home-cat="${c.slug}">${c.name}</button>
        `).join('')}
      </div>`;
  }

  function buildHome() {
    const filtered = state.homeCat
      ? state.posts.filter(p => p.categories?.some(c => c.slug === state.homeCat))
      : state.posts;

    const [hero, ...rest] = filtered;

    // Sezioni per città
    let citySections = '';
    let cityCount = 0;
    state.cities.forEach(city => {
      const cityPosts = state.homeCat
        ? filtered.filter(p => p.cities?.some(c => c.slug === city.slug))
        : state.posts.filter(p => p.cities?.some(c => c.slug === city.slug));
      if (cityPosts.length === 0) return;
      cityCount++;
      if (cityCount > 1 && (cityCount - 1) % 2 === 0) {
        citySections += buildCityAd();
      }
      const shown = cityPosts.slice(0, 3);
      citySections += `
        <div class="dn-section-label" style="margin: 20px 16px 8px">${city.name}</div>
        <div class="dn-feed">
          ${shown.map(p => buildArticleCard(p)).join('')}
        </div>
        <div style="padding: 4px 16px 12px; text-align: right">
          <button class="dn-city-more" data-goto-city="${city.slug}">Vedi altro</button>
        </div>`;
    });

    return `
      <div class="dn-screen" id="screen-home">
        <div class="dn-top-header">
          <h1 class="dn-site-title">Domizio News</h1>
        </div>
        ${buildCatChipsBar()}
        ${hero ? `
          <div class="dn-feed">
            ${buildHeroCard(hero)}
          </div>` : ''}
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
        <div class="dn-feed">
          ${filtered.length === 0
            ? `<p class="dn-empty" style="padding:40px 16px">Nessun articolo per questa città.</p>`
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
              ${c.name}
            </button>
          `).join('')}
        </div>
        <div class="dn-feed">
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
        <div style="padding: 0 16px 16px">
          <div class="dn-search-wrap">
            <input id="dn-search-input" type="search" placeholder="Cerca notizie..." value="${q}" autocomplete="off">
          </div>
        </div>
        <div class="dn-feed">
          ${q.length < 2
            ? `<p class="dn-empty" style="padding:60px 16px 0">Digita almeno 2 caratteri</p>`
            : filtered.length === 0
              ? `<p class="dn-empty" style="padding:60px 16px 0">Nessun risultato per "<b>${q}</b>"</p>`
              : `<p style="font-size:13px;color:#5F6368;padding:0 16px 8px">${filtered.length} risultati</p>
                 ${filtered.map(p => buildArticleCard(p)).join('')}`}
        </div>
      </div>`;
  }

  function buildArticleDetail(post) {
    const date = new Date(post.date).toLocaleDateString('it-IT', { day: 'numeric', month: 'long', year: 'numeric' });
    return `
      <div class="dn-detail">
        <div class="dn-detail-header">
          <button class="dn-back-btn" id="dn-back">Indietro</button>
          <button class="dn-share-btn" id="dn-share">Condividi</button>
        </div>
        ${post.image ? `
          <div class="dn-detail-img-wrap">
            <img src="${post.image}" alt="">
            <div class="dn-detail-img-fade"></div>
          </div>` : ''}
        <div class="dn-detail-body">
          <div class="dn-badges">
            ${post.categories?.map(c => `<span class="dn-badge-cat">${c.name}</span>`).join('') || ''}
            ${post.cities?.map(c => `<span class="dn-badge-city">${c.name}</span>`).join('') || ''}
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
          <div class="dn-ad-slot" data-slot="3" style="width:100%;height:100px;background:#F2F2F2;border-top:1px solid #E0E0E0;border-bottom:1px solid #E0E0E0;display:flex;align-items:center;justify-content:center;margin:20px 0;box-sizing:border-box;">
            <span style="font-size:13px;color:#9AA0A6;">Pubblicità</span>
          </div>
        </div>
      </div>`;
  }

  function buildNav() {
    const tabs = [
      { id: 'home',       label: 'Home' },
      { id: 'cities',     label: 'Città' },
      { id: 'categories', label: 'Categorie' },
      { id: 'search',     label: 'Cerca' },
    ];
    return `
      <nav class="dn-bottom-nav">
        ${tabs.map(t => `
          <button class="dn-nav-tab ${state.tab === t.id ? 'active' : ''}" data-tab="${t.id}">
            <span>${t.label}</span>
          </button>
        `).join('')}
      </nav>`;
  }

  function buildLoading() {
    return `
      <div class="dn-loading">
        <h2>Domizio News</h2>
        <p>Caricamento notizie...</p>
      </div>`;
  }

  // ─── STYLES ─────────────────────────────────────────────────────────────────
  const STYLES = `
    @import url('https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap');

    :root {
      --color-text: #202124;
      --color-text-secondary: #5F6368;
      --color-primary: #1A73E8;
      --color-divider: #E0E0E0;
      --color-background: #FFFFFF;
      --color-card: #FFFFFF;
      --color-chip-inactive-bg: #F2F2F2;
      --color-chip-active-bg: #E8F0FE;
      --color-chip-active-text: #1A73E8;
    }

    * { font-family: 'Roboto', Arial, sans-serif; }
    .dn-app { font-family: 'Roboto', Arial, sans-serif; background: var(--color-background); min-height: 100vh; padding-bottom: 64px; }

    /* LOADING */
    .dn-loading { height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; background: var(--color-text); color: #fff; gap: 8px; }
    .dn-loading h2 { font-family: 'Roboto', Arial, sans-serif; font-weight: 700; font-size: 26px; margin: 0; }
    .dn-loading p { color: var(--color-primary); font-size: 12px; letter-spacing: 2px; text-transform: uppercase; margin: 0; }

    /* TOP HEADER */
    .dn-top-header { padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; background: var(--color-card); border-bottom: 1px solid var(--color-divider); }
    .dn-site-title { margin: 0; font-size: 20px; font-weight: 700; color: var(--color-text); font-family: 'Roboto', Arial, sans-serif; line-height: 1; }

    /* PAGE HEADER (tabs secondari) */
    .dn-page-header { padding: 16px 16px 0; }
    .dn-page-header h2 { margin: 0 0 16px; font-size: 20px; font-weight: 700; color: var(--color-text); font-family: 'Roboto', Arial, sans-serif; }

    /* CATEGORY CHIPS BAR (home) */
    .dn-home-chips { display: flex; gap: 8px; overflow-x: auto; padding: 8px 16px; border-bottom: 1px solid var(--color-divider); background: var(--color-card); scrollbar-width: none; -ms-overflow-style: none; }
    .dn-home-chips::-webkit-scrollbar { display: none; }
    .dn-home-chip { flex-shrink: 0; padding: 8px 16px; border-radius: 16px; border: none; cursor: pointer; font-size: 13px; font-weight: 500; background: var(--color-chip-inactive-bg); color: var(--color-text); transition: all 0.15s; font-family: 'Roboto', Arial, sans-serif; white-space: nowrap; }
    .dn-home-chip.active { background: var(--color-chip-active-bg); color: var(--color-chip-active-text); }

    /* SECTION LABELS (intestazioni città in home) */
    .dn-section-label { font-size: 13px; font-weight: 700; color: var(--color-text); padding-left: 16px; margin-bottom: 0; }
    .dn-city-more { background: none; border: none; cursor: pointer; color: var(--color-primary); font-size: 13px; font-weight: 500; font-family: 'Roboto', Arial, sans-serif; padding: 0; }
    .dn-city-more:active { opacity: 0.7; }

    /* FEED CONTAINER */
    .dn-feed { background: var(--color-background); }

    /* HERO CARD (prima notizia) */
    .dn-card-hero { cursor: pointer; background: var(--color-card); border-bottom: 1px solid var(--color-divider); }
    .dn-card-hero:active { opacity: 0.8; }
    .dn-card-hero-img { width: 100%; aspect-ratio: 16/9; overflow: hidden; padding: 0 16px; box-sizing: border-box; }
    .dn-card-hero-img img { width: 100%; height: 100%; object-fit: cover; display: block; border-radius: 8px; }
    .dn-card-hero-body { padding: 12px 16px 16px; }
    .dn-card-hero-title { margin: 0 0 6px; font-size: 20px; font-weight: 700; color: var(--color-text); font-family: 'Roboto', Arial, sans-serif; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }

    /* LIST CARDS */
    .dn-card-list { display: flex; gap: 12px; padding: 16px; border-bottom: 1px solid var(--color-divider); background: var(--color-card); cursor: pointer; align-items: flex-start; transition: background 0.1s; }
    .dn-card-list:active { background: #F8F9FA; }
    .dn-card-list > img { width: 96px; height: 96px; object-fit: cover; border-radius: 8px; flex-shrink: 0; }
    .dn-card-body { flex: 1; min-width: 0; }
    .dn-card-body h3 { margin: 0 0 6px; font-size: 16px; font-weight: 500; color: var(--color-text); font-family: 'Roboto', Arial, sans-serif; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

    /* CARD BADGES (città + categoria) */
    .dn-card-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 4px; }
    .dn-cat-label { font-size: 11px; font-weight: 500; color: var(--color-primary); background: var(--color-chip-active-bg); padding: 2px 8px; border-radius: 4px; }
    .dn-city-label { font-size: 11px; font-weight: 500; color: var(--color-text-secondary); background: var(--color-chip-inactive-bg); padding: 2px 8px; border-radius: 4px; }

    /* TIME */
    .dn-time { font-size: 12px; font-weight: 400; color: var(--color-text-secondary); display: block; margin-top: 4px; }

    /* CHIPS (tab Città) */
    .dn-chips-scroll { display: flex; gap: 8px; overflow-x: auto; padding: 8px 16px 16px; scrollbar-width: none; -ms-overflow-style: none; }
    .dn-chips-scroll::-webkit-scrollbar { display: none; }
    .dn-chip { flex-shrink: 0; padding: 8px 16px; border-radius: 16px; border: none; cursor: pointer; font-size: 13px; font-weight: 500; background: var(--color-chip-inactive-bg); color: var(--color-text); transition: all 0.15s; font-family: 'Roboto', Arial, sans-serif; }
    .dn-chip.active { background: var(--color-chip-active-bg); color: var(--color-chip-active-text); }

    /* CATEGORY GRID */
    .dn-cat-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; padding: 0 16px 20px; }
    .dn-cat-tile { padding: 14px 8px; border-radius: 8px; border: 1px solid var(--color-divider); cursor: pointer; font-size: 13px; font-weight: 500; line-height: 1.3; background: var(--color-card); color: var(--color-text); transition: all 0.15s; font-family: 'Roboto', Arial, sans-serif; text-align: center; }
    .dn-cat-tile.active { background: var(--color-chip-active-bg); border-color: var(--color-primary); color: var(--color-primary); }

    /* SEARCH */
    .dn-search-wrap { position: relative; }
    #dn-search-input { width: 100%; padding: 12px 14px; border-radius: 24px; border: 1px solid var(--color-divider); background: #F1F3F4; font-size: 16px; outline: none; font-family: 'Roboto', Arial, sans-serif; box-sizing: border-box; color: var(--color-text); }
    #dn-search-input:focus { border-color: var(--color-primary); background: var(--color-card); }

    /* EMPTY */
    .dn-empty { color: var(--color-text-secondary); text-align: center; font-size: 15px; }

    /* ARTICLE DETAIL */
    .dn-detail { min-height: 100vh; background: var(--color-background); padding-bottom: 80px; }
    .dn-detail-header { position: sticky; top: 0; z-index: 10; background: rgba(255,255,255,0.97); backdrop-filter: blur(12px); padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--color-divider); }
    .dn-back-btn { background: none; border: none; cursor: pointer; color: var(--color-primary); font-size: 15px; font-weight: 500; padding: 0; font-family: 'Roboto', Arial, sans-serif; }
    .dn-share-btn { background: none; border: none; cursor: pointer; color: var(--color-text-secondary); font-size: 14px; font-weight: 500; padding: 0; font-family: 'Roboto', Arial, sans-serif; }
    .dn-detail-img-wrap { position: relative; width: 100%; aspect-ratio: 16/9; overflow: hidden; }
    .dn-detail-img-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; border-radius: 8px; }
    .dn-detail-img-fade { position: absolute; inset: 0; background: linear-gradient(to top, var(--color-background) 0%, transparent 50%); }
    .dn-detail-body { padding: 0 16px; margin-top: -20px; }
    .dn-badges { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
    .dn-badge-cat { background: var(--color-primary); color: #fff; font-size: 11px; font-weight: 500; text-transform: uppercase; padding: 3px 10px; border-radius: 4px; }
    .dn-badge-city { background: var(--color-divider); color: var(--color-text-secondary); font-size: 12px; padding: 3px 10px; border-radius: 4px; }
    .dn-detail-title { margin: 0 0 12px; font-size: 28px; font-weight: 700; color: var(--color-text); font-family: 'Roboto', Arial, sans-serif; line-height: 1.2; }
    .dn-detail-byline { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--color-divider); }
    .dn-avatar { width: 28px; height: 28px; border-radius: 50%; background: var(--color-primary); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 500; flex-shrink: 0; }
    .dn-byline-name { font-size: 13px; font-weight: 500; color: var(--color-text-secondary); }
    .dn-byline-date { font-size: 13px; color: var(--color-text-secondary); }
    .dn-detail-content { font-size: 17px; line-height: 1.65; color: var(--color-text); font-family: 'Roboto', Arial, sans-serif; }
    .dn-detail-content p { margin: 0 0 16px; }
    .dn-detail-content strong { color: var(--color-text); font-weight: 700; }
    .dn-local-context { background: #E8F0FE; border-left: 3px solid var(--color-primary); padding: 12px; border-radius: 0 8px 8px 0; font-size: 14px !important; }

    /* BOTTOM NAV */
    .dn-bottom-nav { position: fixed; bottom: 0; left: 50%; transform: translateX(-50%); width: 100%; max-width: 430px; background: var(--color-card); border-top: 1px solid var(--color-divider); display: flex; padding-bottom: env(safe-area-inset-bottom); z-index: 100; }
    .dn-nav-tab { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; background: none; border: none; cursor: pointer; padding: 12px 0; color: var(--color-text-secondary); transition: color 0.15s; font-size: 13px; font-weight: 500; font-family: 'Roboto', Arial, sans-serif; }
    .dn-nav-tab.active { color: var(--color-primary); border-top: 2px solid var(--color-primary); padding-top: 10px; }
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

    // Home category chips
    document.querySelectorAll('[data-home-cat]').forEach(el => {
      el.addEventListener('click', () => {
        setState({ homeCat: el.dataset.homeCat });
      });
    });

    // Home city "Vedi altro" links
    document.querySelectorAll('[data-goto-city]').forEach(el => {
      el.addEventListener('click', () => setState({ tab: 'cities', selectedCity: el.dataset.gotoCity }));
    });

    // Search input
    const searchInput = document.getElementById('dn-search-input');
    if (searchInput) {
      searchInput.addEventListener('input', (e) => {
        state.searchQuery = e.target.value;
        render();
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
