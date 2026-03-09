/* Domizio News App — standalone bundle (UMD, no build required)
   Carica React da CDN tramite importmap nel template. */

(function () {

  // ─── CONFIG: legge da window.DNAPP_CONFIG iniettato da WordPress ────────────
  const CFG = window.DNAPP_CONFIG || {};
  const API = CFG.wpBase ? CFG.wpBase.replace(/\/$/, '') : '';
  const CUSTOM_API  = API.replace('/wp/v2', '') + '/dnapp/v1';
  const DOMIZIO_API = API.replace('/wp/v2', '') + '/domizio/v1';
  const STICKY_API  = DOMIZIO_API + '/sticky-news';

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
    stickyNews: [],
    loading: true,
    searchQuery: '',
    selectedCity: '',
    selectedCat: '',
    activeHomeCity: '',     // slug chip attivo nella home ('' = Tutte)
    homeCityPosts:  {},     // map slug → posts[] per le sezioni home
    cityFeed: [],           // post caricati server-side per la città selezionata (tab Città)
    cityFeedLoading: false, // spinner mentre si aspetta la risposta
  };

  function setState(patch) {
    state = Object.assign({}, state, patch);
    render();
  }

  // ─── API ────────────────────────────────────────────────────────────────────

  // Slug esatti registrati nel database — usati sia per la home che per il tab Città
  const CITY_SLUGS = [
    'mondragone',
    'castel-volturno',
    'baia-domizia',
    'cellole',
    'falciano-del-massico',
    'carinola',
    'sessa-aurunca',
  ];

  // Carica post filtrati per città (tab Città).
  // GET /wp-json/domizio/v1/posts?city=SLUG&per_page=20
  async function loadCityFeed(slug) {
    if (!slug) {
      setState({ cityFeed: [], cityFeedLoading: false });
      return;
    }
    const url = DOMIZIO_API + '/posts?city=' + encodeURIComponent(slug) + '&per_page=20';
    console.log('[DomizioNews] fetch città:', url);
    try {
      const res  = await fetch(url);
      const data = await res.json();
      setState({ cityFeed: data.posts || [], cityFeedLoading: false });
    } catch (e) {
      console.error('[DomizioNews] errore fetch città:', e);
      setState({ cityFeedLoading: false });
    }
  }

  async function loadData() {
    try {
      // Fetch feed principale, config, sticky news e i 7 feed città in parallelo.
      // Le sezioni home usano dati server-side così Cellole/Falciano/Carinola
      // vengono trovate anche se non rientrano nei primi 20 post generali.
      const [feed, config, sticky, ...cityResults] = await Promise.all([
        fetch(CUSTOM_API + '/feed?per_page=20').then(r => r.json()),
        fetch(CUSTOM_API + '/config').then(r => r.json()),
        fetch(STICKY_API).then(r => r.ok ? r.json() : []).catch(() => []),
        ...CITY_SLUGS.map(slug =>
          fetch(DOMIZIO_API + '/posts?city=' + encodeURIComponent(slug) + '&per_page=5')
            .then(r => r.json())
            .catch(() => ({ posts: [] }))
        ),
      ]);

      const homeCityPosts = {};
      CITY_SLUGS.forEach((slug, i) => {
        homeCityPosts[slug] = cityResults[i]?.posts || [];
      });

      setState({
        posts:         feed.posts || [],
        cities:        config.cities || [],
        categories:    config.categories || [],
        stickyNews:    Array.isArray(sticky) ? sticky : [],
        homeCityPosts: homeCityPosts,
        loading:       false,
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

  // ─── CARD BADGES ─────────────────────────────────────────────────────────────
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

  // Hero card: immagine full-width 16/9
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

  // List card: thumbnail 80x80 a destra
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

  // ─── CHIP MENU CITTÀ (home) ──────────────────────────────────────────────────
  const HOME_CITIES = [
    { slug: '',                     name: 'Tutte' },
    { slug: 'mondragone',           name: 'Mondragone' },
    { slug: 'castel-volturno',      name: 'Castel Volturno' },
    { slug: 'baia-domizia',         name: 'Baia Domizia' },
    { slug: 'cellole',              name: 'Cellole' },
    { slug: 'falciano-del-massico', name: 'Falciano' },
    { slug: 'carinola',             name: 'Carinola' },
    { slug: 'sessa-aurunca',        name: 'Sessa Aurunca' },
  ];

  function buildCityChipsBar() {
    return `
      <div class="dn-home-chips" id="dn-city-chips">
        ${HOME_CITIES.map(c => `
          <button class="dn-home-chip ${state.activeHomeCity === c.slug ? 'active' : ''}" data-home-city="${c.slug}">${c.name}</button>
        `).join('')}
      </div>`;
  }

  // ─── SLIDER NOTIZIE IN EVIDENZA ──────────────────────────────────────────────
  // Usa i dati da /wp-json/domizio/v1/sticky-news (una card per città).
  // Fallback: ultime notizie generiche se l'endpoint non ha risposto.
  function buildSlider() {
    const items = state.stickyNews.length > 0
      ? state.stickyNews
      : state.posts.slice(0, 5).map(p => ({
          post_id:   p.id,
          title:     p.title,
          image:     p.image || '',
          category:  p.categories?.[0]?.name || '',
          city:      p.cities?.[0]?.name || '',
          city_slug: p.cities?.[0]?.slug || '',
          time_ago:  timeAgo(p.date),
          permalink: p.link || '',
          is_vip:    !!p.sticky,
        }));

    if (items.length === 0) return '';

    return `
      <div class="dn-slider-wrap">
        <div class="dn-slider" id="dn-slider">
          ${items.map(item => `
            <div class="dn-slider-card" data-sticky-href="${item.permalink}" data-post-id="${item.post_id}">
              ${item.image ? `<div class="dn-slider-img"><img src="${item.image}" alt="" loading="lazy"></div>` : ''}
              <div class="dn-slider-body">
                <div class="dn-card-badges">
                  ${item.category ? `<span class="dn-cat-label">${item.category}</span>` : ''}
                  ${item.city     ? `<span class="dn-city-label">${item.city}</span>` : ''}
                  ${item.is_vip   ? `<span class="dn-vip-badge">In evidenza</span>` : ''}
                </div>
                <h3 class="dn-slider-title">${item.title}</h3>
                <span class="dn-time">${item.time_ago}</span>
              </div>
            </div>`).join('')}
        </div>
        <div class="dn-slider-dots" id="dn-slider-dots">
          ${items.map((_, i) => `<span class="dn-dot ${i === 0 ? 'active' : ''}"></span>`).join('')}
        </div>
      </div>`;
  }

  // ─── HOME: sezioni per città ─────────────────────────────────────────────────
  function buildHome() {
    let citySections = '';
    let cityCount = 0;
    const activeSlug = state.activeHomeCity; // '' = Tutte

    state.cities.forEach(city => {
      // Chip attivo: mostra solo la sezione della città selezionata
      if (activeSlug && city.slug !== activeSlug) return;

      // Post caricati server-side per questa città (slug esatto nel DB)
      const cityPosts = state.homeCityPosts[city.slug] || [];
      if (cityPosts.length === 0) return;
      cityCount++;
      if (cityCount > 1 && (cityCount - 1) % 2 === 0) {
        citySections += buildCityAd();
      }
      const shown = cityPosts.slice(0, 3);
      citySections += `
        <div class="dn-city-section" id="city-section-${city.slug}">
          <div class="dn-section-label">${city.name}</div>
          <div class="dn-feed">
            ${shown.map(p => buildArticleCard(p)).join('')}
          </div>
          <div class="dn-city-more-wrap">
            <button class="dn-city-more" data-goto-city="${city.slug}">Vedi altro</button>
          </div>
        </div>
        <div class="dn-section-separator"></div>`;
    });

    return `
      <div class="dn-screen" id="screen-home">
        <div class="dn-top-header">
          <h1 class="dn-site-title">Domizio News</h1>
        </div>
        ${buildCityChipsBar()}
        ${buildSlider()}
        ${citySections}
      </div>`;
  }

  function buildCities() {
    // Il feed per città viene caricato server-side (loadCityFeed) così vengono
    // trovati anche i post di città piccole che non rientrano nei primi 20.
    let feedHtml;
    if (!state.selectedCity) {
      feedHtml = state.posts.length === 0
        ? `<p class="dn-empty" style="padding:40px 16px">Nessuna notizia disponibile.</p>`
        : state.posts.map(p => buildArticleCard(p)).join('');
    } else if (state.cityFeedLoading) {
      feedHtml = `<p class="dn-empty" style="padding:40px 16px">Caricamento...</p>`;
    } else if (state.cityFeed.length === 0) {
      feedHtml = `<p class="dn-empty" style="padding:40px 16px">Nessun articolo per questa città.</p>`;
    } else {
      feedHtml = state.cityFeed.map(p => buildArticleCard(p)).join('');
    }

    return `
      <div class="dn-screen">
        <div class="dn-page-header"><h2>Città</h2></div>
        <div class="dn-chips-scroll">
          ${state.cities.map(c => `
            <button class="dn-chip ${state.selectedCity === c.slug ? 'active' : ''}" data-city="${c.slug}">${c.name}</button>
          `).join('')}
        </div>
        <div class="dn-feed">
          ${feedHtml}
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
      --color-separator: #F2F2F2;
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

    /* CHIP MENU CITTÀ (home) */
    .dn-home-chips { display: flex; gap: 8px; overflow-x: auto; padding: 8px 16px; border-bottom: 1px solid var(--color-divider); background: var(--color-card); scrollbar-width: none; -ms-overflow-style: none; position: sticky; top: 0; z-index: 10; }
    .dn-home-chips::-webkit-scrollbar { display: none; }
    .dn-home-chip { flex-shrink: 0; height: 32px; padding: 0 12px; line-height: 32px; border-radius: 16px; border: none; cursor: pointer; font-size: 13px; font-weight: 500; background: var(--color-chip-inactive-bg); color: var(--color-text); transition: all 0.15s; font-family: 'Roboto', Arial, sans-serif; white-space: nowrap; }
    .dn-home-chip.active { background: var(--color-chip-active-bg); color: var(--color-chip-active-text); }

    /* SLIDER NOTIZIE IN EVIDENZA */
    .dn-slider-wrap { padding: 16px 0 8px; border-bottom: 8px solid var(--color-separator); }
    .dn-slider { display: flex; gap: 12px; overflow-x: auto; padding-left: 16px; padding-right: 4px; scroll-snap-type: x mandatory; scrollbar-width: none; -ms-overflow-style: none; }
    .dn-slider::-webkit-scrollbar { display: none; }
    .dn-slider-card { flex-shrink: 0; width: calc(75% - 6px); scroll-snap-align: start; cursor: pointer; }
    .dn-slider-img { width: 100%; aspect-ratio: 16/9; overflow: hidden; border-radius: 8px; }
    .dn-slider-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .dn-slider-body { padding: 8px 0 0; }
    .dn-slider-title { margin: 6px 0 0; font-size: 16px; font-weight: 700; color: var(--color-text); font-family: 'Roboto', Arial, sans-serif; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .dn-slider-dots { display: flex; gap: 4px; justify-content: center; padding: 10px 0 4px; }
    .dn-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--color-divider); transition: background 0.2s; flex-shrink: 0; }
    .dn-dot.active { background: var(--color-primary); }
    .dn-vip-badge { font-size: 10px; font-weight: 600; color: #fff; background: var(--color-primary); padding: 2px 7px; border-radius: 4px; letter-spacing: .3px; }

    /* SEZIONI CITTÀ */
    .dn-section-label { font-size: 15px; font-weight: 700; color: var(--color-text); padding: 16px 16px 8px; display: block; }
    .dn-section-separator { height: 8px; background: var(--color-separator); }

    /* BOTTONE "VEDI ALTRO" */
    .dn-city-more-wrap { border-top: 1px solid var(--color-divider); }
    .dn-city-more { display: block; width: 100%; padding: 12px 16px; background: none; border: none; cursor: pointer; color: var(--color-primary); font-size: 14px; font-weight: 500; font-family: 'Roboto', Arial, sans-serif; text-align: center; box-sizing: border-box; }
    .dn-city-more:active { opacity: 0.7; }

    /* FEED CONTAINER */
    .dn-feed { background: var(--color-background); }

    /* HERO CARD */
    .dn-card-hero { cursor: pointer; background: var(--color-card); border-bottom: 1px solid var(--color-divider); }
    .dn-card-hero:active { opacity: 0.8; }
    .dn-card-hero-img { width: 100%; aspect-ratio: 16/9; overflow: hidden; padding: 0 16px; box-sizing: border-box; }
    .dn-card-hero-img img { width: 100%; height: 100%; object-fit: cover; display: block; border-radius: 8px; }
    .dn-card-hero-body { padding: 12px 16px 16px; }
    .dn-card-hero-title { margin: 0 0 6px; font-size: 16px; font-weight: 700; color: var(--color-text); font-family: 'Roboto', Arial, sans-serif; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

    /* LIST CARDS */
    .dn-card-list { display: flex; gap: 12px; padding: 16px; border-bottom: 1px solid var(--color-divider); background: var(--color-card); cursor: pointer; align-items: flex-start; transition: background 0.1s; }
    .dn-card-list:active { background: #F8F9FA; }
    .dn-card-list > img { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; flex-shrink: 0; }
    .dn-card-body { flex: 1; min-width: 0; }
    .dn-card-body h3 { margin: 0 0 6px; font-size: 14px; font-weight: 500; color: var(--color-text); font-family: 'Roboto', Arial, sans-serif; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

    /* CARD BADGES (categoria + città) */
    .dn-card-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 6px; }
    .dn-cat-label { font-size: 11px; font-weight: 500; color: var(--color-primary); background: var(--color-chip-active-bg); padding: 2px 8px; border-radius: 4px; }
    .dn-city-label { font-size: 11px; font-weight: 500; color: var(--color-text-secondary); background: var(--color-chip-inactive-bg); padding: 2px 8px; border-radius: 4px; }

    /* TIME */
    .dn-time { font-size: 12px; font-weight: 400; color: var(--color-text-secondary); display: block; margin-top: 6px; }

    /* CHIPS (tab Città) */
    .dn-chips-scroll { display: flex; gap: 8px; overflow-x: auto; padding: 8px 16px 16px; scrollbar-width: none; -ms-overflow-style: none; }
    .dn-chips-scroll::-webkit-scrollbar { display: none; }
    .dn-chip { flex-shrink: 0; height: 32px; padding: 0 12px; line-height: 32px; border-radius: 16px; border: none; cursor: pointer; font-size: 13px; font-weight: 500; background: var(--color-chip-inactive-bg); color: var(--color-text); transition: all 0.15s; font-family: 'Roboto', Arial, sans-serif; }
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
    // Article cards (feed principale, city feed server-side, slider)
    document.querySelectorAll('[data-post-id]').forEach(el => {
      el.addEventListener('click', () => {
        const id   = el.dataset.postId;
        // Cerca nel feed principale e nel city feed (post non presenti nei 20 iniziali)
        const post = state.posts.find(p => p.id == id)
                  || state.cityFeed.find(p => p.id == id);
        if (post) {
          setState({ selectedPost: post });
        } else if (el.dataset.stickyHref) {
          // Post sticky non nel feed locale: apri permalink
          window.location.href = el.dataset.stickyHref;
        }
      });
    });

    // Bottom nav
    document.querySelectorAll('[data-tab]').forEach(el => {
      el.addEventListener('click', () => setState({ tab: el.dataset.tab }));
    });

    // City chips (tab Città) — fetch server-side per slug corretto
    document.querySelectorAll('[data-city]').forEach(el => {
      el.addEventListener('click', () => {
        const slug    = el.dataset.city;
        const newSlug = state.selectedCity === slug ? '' : slug;
        setState({ selectedCity: newSlug, cityFeed: [], cityFeedLoading: !!newSlug });
        loadCityFeed(newSlug);
      });
    });

    // Category tiles
    document.querySelectorAll('[data-cat]').forEach(el => {
      el.addEventListener('click', () => {
        const slug = el.dataset.cat;
        setState({ selectedCat: state.selectedCat === slug ? '' : slug });
      });
    });

    // Chip menu città (home) — filtra le sezioni via state (re-render)
    document.querySelectorAll('[data-home-city]').forEach(el => {
      el.addEventListener('click', () => {
        const slug = el.dataset.homeCity;
        console.log('activeCity:', slug || 'tutte');
        setState({ activeHomeCity: slug });
        // Scroll verso l'alto così le sezioni sono subito visibili
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });

    // Home city "Vedi altro" links — passa alla tab Città e carica feed server-side
    document.querySelectorAll('[data-goto-city]').forEach(el => {
      el.addEventListener('click', () => {
        const slug = el.dataset.gotoCity;
        setState({ tab: 'cities', selectedCity: slug, cityFeed: [], cityFeedLoading: true });
        loadCityFeed(slug);
      });
    });

    // Slider — aggiorna dots al scroll
    const slider = document.getElementById('dn-slider');
    const dotsEl = document.getElementById('dn-slider-dots');
    if (slider && dotsEl) {
      slider.addEventListener('scroll', () => {
        const cardEl = slider.firstElementChild;
        if (!cardEl) return;
        const cardWidth = cardEl.offsetWidth + 12; // card + gap
        const index = Math.min(
          Math.round(slider.scrollLeft / cardWidth),
          dotsEl.children.length - 1
        );
        Array.from(dotsEl.children).forEach((dot, i) => {
          dot.classList.toggle('active', i === index);
        });
      }, { passive: true });
    }

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
