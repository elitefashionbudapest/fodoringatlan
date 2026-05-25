const { useState, useMemo, useEffect, useRef, useCallback } = React;

// =================== RESPONSIVE HOOK ===================
function useWindowWidth() {
  const [w, setW] = React.useState(window.innerWidth);
  React.useEffect(() => {
    const h = () => setW(window.innerWidth);
    window.addEventListener('resize', h);
    return () => window.removeEventListener('resize', h);
  }, []);
  return w;
}

const apiFetch = (url, opts = {}) => {
  const token = localStorage.getItem('fodor_token') || '';
  return fetch(url, {
    ...opts,
    headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', ...(opts.headers || {}) }
  }).then(r => {
    if (r.status === 401) {
      localStorage.removeItem('fodor_token');
      localStorage.removeItem('fodor_user');
      window.location.reload();
      return Promise.reject(new Error('Unauthorized'));
    }
    return r.json();
  });
};

// =================== BRAND ===================
const C = {
  navy: '#1F2D3D',
  navyDeep: '#162232',
  navyMid: '#2C3E51',
  navySoft: '#3A4F66',
  gold: '#B8935A',
  goldDeep: '#9A7843',
  goldSoft: '#D9BC8A',
  cream: '#F5F0E6',
  creamSoft: '#FBF7EE',
  ink: '#0F1A26',
  mute: '#6E7A88',
  line: '#E6DECF',
  lineSoft: '#EFE9DC',
  bg: '#F8F5EE',
  white: '#FFFFFF',
  ok: '#5C8A5B',
  warn: '#C9A05F',
  bad: '#B4584F',
};

// =================== ICONS ===================
const Ico = {
  dash: (p) => <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" {...p}><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>,
  user: (p) => <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" {...p}><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/></svg>,
  bolt: (p) => <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" {...p}><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/></svg>,
  check: (p) => <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" {...p}><path d="m4 12 5 5L20 6"/></svg>,
  chart: (p) => <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" {...p}><path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/></svg>,
  star: (p) => <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" {...p}><path d="M12 2.5 14.7 9l6.8.6-5.2 4.6 1.6 6.7L12 17.4l-5.9 3.5 1.6-6.7L2.5 9.6 9.3 9z"/></svg>,
  mail: (p) => <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" {...p}><rect x="3" y="5" width="18" height="14" rx="1"/><path d="m3 7 9 6 9-6"/></svg>,
  sms: (p) => <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" {...p}><path d="M4 5h16v11H8l-4 4z"/></svg>,
  qr: (p) => <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" {...p}><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3M21 14v7h-7v-3"/></svg>,
  bell: (p) => <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" {...p}><path d="M18 16V11a6 6 0 1 0-12 0v5l-2 3h16z"/><path d="M10 21h4"/></svg>,
  search: (p) => <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" {...p}><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>,
  plus: (p) => <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" {...p}><path d="M12 5v14M5 12h14"/></svg>,
  arrow: (p) => <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" {...p}><path d="M5 12h14M13 5l7 7-7 7"/></svg>,
  dot: (p) => <svg width="6" height="6" viewBox="0 0 6 6" {...p}><circle cx="3" cy="3" r="3" fill="currentColor"/></svg>,
  google: (p) => <svg width="14" height="14" viewBox="0 0 24 24" {...p}><path fill="#4285F4" d="M21.6 12.2c0-.7-.1-1.4-.2-2H12v3.8h5.4c-.2 1.3-.9 2.4-2 3.1v2.6h3.2c1.9-1.7 3-4.3 3-7.5z"/><path fill="#34A853" d="M12 22c2.7 0 5-.9 6.6-2.4l-3.2-2.6c-.9.6-2 1-3.4 1-2.6 0-4.8-1.8-5.6-4.2H3.1v2.6C4.7 19.6 8.1 22 12 22z"/><path fill="#FBBC05" d="M6.4 13.8c-.2-.6-.3-1.2-.3-1.8s.1-1.2.3-1.8V7.6H3.1C2.4 9 2 10.4 2 12s.4 3 1.1 4.4l3.3-2.6z"/><path fill="#EA4335" d="M12 5.8c1.5 0 2.8.5 3.8 1.5l2.8-2.8C16.9 2.9 14.7 2 12 2 8.1 2 4.7 4.4 3.1 7.6l3.3 2.6C7.2 7.6 9.4 5.8 12 5.8z"/></svg>,
  link: (p) => <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" {...p}><path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg>,
  gear: (p) => <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" {...p}><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>,
  inbox: (p) => <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" {...p}><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13L22 12v7a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-7z"/></svg>,
  tag: (p) => <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" {...p}><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7" cy="7" r="1.2"/></svg>,
  filter: (p) => <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" {...p}><path d="M3 4h18l-7 9v6l-4 2v-8z"/></svg>,
  flame: (p) => <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" {...p}><path d="M12 2s4 4 4 8a4 4 0 0 1-1.5 3 3 3 0 0 0-1-3c-.5-1-.5-2 0-3-2 1-4 3-4 6a5.5 5.5 0 0 0 11 0c0-5-4-8-8.5-11z"/></svg>,
};

// =================== STARS ===================
const Stars = ({ value = 5, size = 12, c = C.gold }) => (
  <span style={{display:'inline-flex', gap:1, color:c}}>
    {[1,2,3,4,5].map(i => <Ico.star key={i} width={size} height={size} style={{opacity: i<=value?1:0.18}}/>)}
  </span>
);

// =================== LOGO ===================
const Logo = ({ small, light }) => (
  <div style={{display:'flex', flexDirection:'column', gap:6}}>
    <img
      src="logo.png"
      alt="Fodor Ingatlanközvetítő Kft."
      style={{
        maxWidth: small ? 90 : 120,
        width: '100%',
        height: 'auto',
        display: 'block',
        filter: light ? 'none' : 'brightness(0) invert(1)',
      }}
    />
    <div style={{fontFamily:'"DM Mono", monospace', fontSize:8, color: light ? C.navySoft : C.goldSoft, letterSpacing:2}}>REVIEW · OS</div>
  </div>
);

// =================== SIDEBAR NAV ===================
const NAV = [
  { group: 'ÁTTEKINTÉS', items: [
    { id:'dash',        icon:'dash',  label:'Vezérlőpult' },
    { id:'inbox',       icon:'inbox', label:'Elküldött kérések',  badgeKey:'inbox_new' },
  ]},
  { group: 'KEZELÉS', items: [
    { id:'profiles',    icon:'user',  label:'Profilok & Ügynökök', badgeKey:'agent_count' },
    { id:'automations', icon:'bolt',  label:'Automatizmusok',      badgeKey:'active_automations' },
    { id:'verify',      icon:'check', label:'Visszaellenőrzés',    badgeKey:'verify_pending' },
    { id:'templates',   icon:'tag',   label:'Üzenetsablonok',      badgeKey:'template_count' },
  ]},
  { group: 'ELEMZÉS', items: [
    { id:'stats',       icon:'chart', label:'Statisztikák' },
  ]},
  { group: 'BEÁLLÍTÁS', items: [
    { id:'settings',    icon:'gear',  label:'Beállítások' },
  ]},
];

// =================== SIDEBAR ===================
function Sidebar({ active, setActive, onLogout, isOpen, onClose }) {
  const w = useWindowWidth();
  const isMobile = w < 768;
  const storedUser = JSON.parse(localStorage.getItem('fodor_user') || '{}');
  const initials = (storedUser.name || 'FK').split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();
  const roleLabel = (storedUser.role || 'admin').toUpperCase();
  const userName  = storedUser.name || 'Admin';

  const [navCounts, setNavCounts] = React.useState({});
  const [searchOpen, setSearchOpen] = React.useState(false);
  const [searchQ, setSearchQ] = React.useState('');
  const [searchResults, setSearchResults] = React.useState([]);
  const [searchLoading, setSearchLoading] = React.useState(false);

  React.useEffect(() => {
    const handler = (e) => { if ((e.metaKey || e.ctrlKey) && e.key === 'k') { e.preventDefault(); setSearchOpen(true); } };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, []);

  React.useEffect(() => {
    if (!searchOpen) { setSearchQ(''); setSearchResults([]); return; }
  }, [searchOpen]);

  React.useEffect(() => {
    if (!searchQ || searchQ.length < 2) { setSearchResults([]); return; }
    setSearchLoading(true);
    const token = localStorage.getItem('fodor_token') || '';
    const h = { 'Authorization': 'Bearer ' + token };
    Promise.all([
      fetch(`api/contacts.php?q=${encodeURIComponent(searchQ)}&per_page=5`, { headers: h }).then(r=>r.json()).catch(()=>({})),
      fetch(`api/agents.php?q=${encodeURIComponent(searchQ)}`, { headers: h }).then(r=>r.json()).catch(()=>({})),
    ]).then(([contacts, agents]) => {
      const results = [];
      (contacts.data || []).forEach(c => results.push({ type:'contact', label: c.name, sub: c.email || c.phone || '', nav:'profiles' }));
      (agents.data || []).forEach(a => results.push({ type:'agent', label: a.name, sub: a.role || 'ügynök', nav:'profiles' }));
      setSearchResults(results);
      setSearchLoading(false);
    });
  }, [searchQ]);

  React.useEffect(() => {
    const token = localStorage.getItem('fodor_token') || '';
    const h = { 'Authorization': 'Bearer ' + token };
    Promise.all([
      fetch('api/stats.php', { headers: h }).then(r => r.json()).catch(() => ({})),
      fetch('api/agents.php', { headers: h }).then(r => r.json()).catch(() => ({})),
      fetch('api/templates.php', { headers: h }).then(r => r.json()).catch(() => ({})),
      fetch('api/verify.php', { headers: h }).then(r => r.json()).catch(() => ({})),
      fetch('api/reviews.php?limit=1', { headers: h }).then(r => r.json()).catch(() => ({})),
    ]).then(([stats, agents, tpls, verify, reviews]) => {
      const kpi = stats.kpi || {};
      const agentCount = (agents.data || []).length || 0;
      const tplCount   = (tpls.data || []).length || 0;
      const pending    = (verify.sla_monitor || verify.data || []).filter(x => x.state === 'sent' || x.state === 'waiting').length;
      const inboxNew   = kpi.new_reviews_month || 0;
      setNavCounts({
        inbox_new:          inboxNew   || null,
        agent_count:        agentCount || null,
        active_automations: kpi.active_automations || null,
        verify_pending:     pending    || null,
        template_count:     tplCount   || null,
      });
    });
  }, [active]);

  const handleNavClick = (id) => {
    setActive(id);
    if (isMobile && onClose) onClose();
  };

  return (
    <aside style={{
      width: 232, background: C.navy, color: C.cream, display:'flex', flexDirection:'column',
      borderRight:`1px solid ${C.navyDeep}`, flexShrink:0,
      ...(isMobile ? {
        position:'fixed', top:0, left:0, height:'100vh', zIndex:500,
        transform: isOpen ? 'translateX(0)' : 'translateX(-232px)',
        transition:'transform 0.25s ease',
        boxShadow: isOpen ? '4px 0 24px rgba(0,0,0,0.35)' : 'none',
      } : {
        position:'sticky', top:0, height:'100vh',
      })
    }}>
      <div style={{padding:'18px 18px 16px', borderBottom:`1px solid ${C.navyDeep}`}}>
        <Logo/>
      </div>

      <div style={{padding:'10px 12px', borderBottom:`1px solid ${C.navyDeep}`}}>
        <button onClick={() => setSearchOpen(true)} style={{
          width:'100%', background: C.navyDeep, borderRadius:8, padding:'8px 10px', border:'none', cursor:'pointer',
          display:'flex', alignItems:'center', gap:8, color: C.goldSoft, fontSize:11.5, fontFamily:'inherit'
        }}>
          <Ico.search/>
          <span style={{flex:1, textAlign:'left'}}>Keresés...</span>
          <kbd style={{fontFamily:'"DM Mono", monospace', fontSize:9.5, background:C.navy, padding:'2px 5px', borderRadius:3, color:C.goldSoft, border:`1px solid ${C.navySoft}`}}>⌘K</kbd>
        </button>
      </div>

      {searchOpen && (
        <div style={{position:'fixed', inset:0, background:'rgba(15,26,38,0.7)', zIndex:9999, display:'flex', alignItems:'flex-start', justifyContent:'center', paddingTop:80}} onClick={() => setSearchOpen(false)}>
          <div style={{background:C.white, borderRadius:12, width:520, maxWidth:'90vw', overflow:'hidden', boxShadow:'0 20px 60px rgba(0,0,0,0.3)'}} onClick={e => e.stopPropagation()}>
            <div style={{display:'flex', alignItems:'center', gap:10, padding:'14px 16px', borderBottom:`1px solid ${C.line}`}}>
              <Ico.search style={{color:C.mute, flexShrink:0}}/>
              <input
                autoFocus
                value={searchQ}
                onChange={e => setSearchQ(e.target.value)}
                placeholder="Partner, ügynök neve vagy email..."
                style={{flex:1, border:'none', outline:'none', fontSize:14, color:C.navyDeep, fontFamily:'inherit', background:'transparent'}}
                onKeyDown={e => { if(e.key==='Escape') setSearchOpen(false); }}
              />
              {searchLoading && <span style={{fontSize:11, color:C.mute}}>…</span>}
            </div>
            <div style={{maxHeight:300, overflowY:'auto'}}>
              {searchResults.length === 0 && searchQ.length >= 2 && !searchLoading && (
                <div style={{padding:'20px 16px', color:C.mute, fontSize:13, textAlign:'center'}}>Nincs találat: „{searchQ}"</div>
              )}
              {searchResults.length === 0 && searchQ.length < 2 && (
                <div style={{padding:'20px 16px', color:C.mute, fontSize:12, textAlign:'center'}}>Írj legalább 2 karaktert…</div>
              )}
              {searchResults.map((r, i) => (
                <button key={i} onClick={() => { setActive(r.nav); setSearchOpen(false); }} style={{
                  width:'100%', display:'flex', alignItems:'center', gap:12, padding:'10px 16px', border:'none',
                  borderBottom:`1px solid ${C.lineSoft}`, background:'transparent', cursor:'pointer', textAlign:'left', fontFamily:'inherit'
                }}>
                  <div style={{width:28, height:28, borderRadius:6, background: r.type==='agent' ? C.navy : C.creamSoft, display:'flex', alignItems:'center', justifyContent:'center', flexShrink:0}}>
                    {r.type==='agent' ? <Ico.user style={{color:C.cream}}/> : <Ico.user style={{color:C.navy}}/>}
                  </div>
                  <div>
                    <div style={{fontSize:13, fontWeight:600, color:C.navyDeep}}>{r.label}</div>
                    <div style={{fontSize:11, color:C.mute, fontFamily:'"DM Mono", monospace'}}>{r.type === 'agent' ? 'ÜGYNÖK' : 'PARTNER'} · {r.sub}</div>
                  </div>
                </button>
              ))}
            </div>
          </div>
        </div>
      )}

      <nav style={{flex:1, overflowY:'auto', padding:'12px 8px'}}>
        {NAV.map(group => (
          <div key={group.group} style={{marginBottom:14}}>
            <div style={{fontSize:9.5, fontFamily:'"DM Mono", monospace', letterSpacing:1.6, color:'#6F839A', padding:'4px 10px 6px'}}>{group.group}</div>
            {group.items.map(it => {
              const I = Ico[it.icon];
              const on = active === it.id;
              return (
                <button key={it.id} onClick={()=>handleNavClick(it.id)} style={{
                  width:'100%', display:'flex', alignItems:'center', gap:10,
                  padding:'8px 10px', borderRadius:6, border:'none', cursor:'pointer',
                  background: on ? C.gold : 'transparent',
                  color: on ? C.navyDeep : C.cream,
                  fontSize:12.5, fontWeight: on?600:500, letterSpacing:0.1,
                  marginBottom:2, position:'relative',
                  fontFamily:'inherit'
                }}>
                  <I/>
                  <span style={{flex:1, textAlign:'left'}}>{it.label}</span>
                  {it.badgeKey && navCounts[it.badgeKey] ? <span style={{
                    fontSize:10, fontFamily:'"DM Mono", monospace',
                    background: on ? C.navyDeep : C.navySoft,
                    color: on ? C.goldSoft : C.cream,
                    padding:'1px 6px', borderRadius:10
                  }}>{navCounts[it.badgeKey]}</span> : null}
                </button>
              );
            })}
          </div>
        ))}
      </nav>

      <div style={{padding:12, borderTop:`1px solid ${C.navyDeep}`, display:'flex', alignItems:'center', gap:10}}>
        <div style={{width:32, height:32, borderRadius:'50%', background:`linear-gradient(135deg, ${C.gold}, ${C.goldDeep})`, display:'flex', alignItems:'center', justifyContent:'center', fontWeight:700, color:C.navyDeep, fontSize:12}}>{initials}</div>
        <div style={{flex:1, minWidth:0}}>
          <div style={{fontSize:12, fontWeight:600}}>{userName}</div>
          <div style={{fontSize:10.5, color:C.goldSoft, fontFamily:'"DM Mono", monospace'}}>{roleLabel}</div>
        </div>
        <button title="Kijelentkezés" onClick={onLogout} style={{background:'transparent', border:'none', color:C.goldSoft, cursor:'pointer', fontSize:16, lineHeight:1}}>⏻</button>
      </div>
    </aside>
  );
}

// =================== TOPBAR HELPERS ===================
const pillBtn = (primary) => ({
  display:'inline-flex', alignItems:'center', gap:6,
  padding:'7px 12px', borderRadius:6, fontSize:12, fontWeight:600,
  border:`1px solid ${primary?C.navyDeep:C.line}`,
  background: primary?C.navyDeep:C.white,
  color: primary?C.cream:C.navy,
  cursor:'pointer', fontFamily:'inherit'
});
const iconBtn = () => ({
  width:34, height:34, borderRadius:6, border:`1px solid ${C.line}`,
  background:C.white, color:C.navy, cursor:'pointer', display:'inline-flex',
  alignItems:'center', justifyContent:'center', position:'relative'
});

// =================== TOPBAR ===================
function TopBar({ title, subtitle, crumbs, onNewRequest }) {
  return (
    <div style={{
      padding:'18px 28px 16px', background:C.creamSoft,
      borderBottom:`1px solid ${C.line}`,
      display:'flex', alignItems:'flex-end', justifyContent:'space-between'
    }}>
      <div>
        <div style={{fontSize:10.5, fontFamily:'"DM Mono", monospace', color:C.mute, letterSpacing:1.3, marginBottom:6}}>
          {(crumbs||[]).map((c,i)=>(<span key={i}>{i>0 && ' · '}{c}</span>))}
        </div>
        <h1 style={{fontSize:24, fontFamily:'"DM Serif Display", serif', color:C.navyDeep, margin:0, letterSpacing:-0.3}}>{title}</h1>
        {subtitle && <div style={{fontSize:12.5, color:C.mute, marginTop:4}}>{subtitle}</div>}
      </div>
      <div style={{display:'flex', alignItems:'center', gap:10}}>
        <div style={{display:'flex', gap:6, alignItems:'center', background:C.white, border:`1px solid ${C.line}`, borderRadius:6, padding:'6px 10px', fontSize:11.5, color:C.navy}}>
          <Ico.dot style={{color:C.ok}}/> Élő · <span style={{fontFamily:'"DM Mono", monospace', color:C.mute}}>API</span>
        </div>
        <button style={pillBtn(false)}><Ico.filter/> Szűrő</button>
        <button style={pillBtn(true)} onClick={onNewRequest}><Ico.plus/> Új kérés</button>
        <button style={iconBtn()}><Ico.bell/><span style={{position:'absolute', top:6, right:6, width:6, height:6, borderRadius:'50%', background:C.gold}}/></button>
      </div>
    </div>
  );
}

// =================== STAT CARD ===================
function Stat({ label, value, unit, delta, deltaPos = true, spark, accent }) {
  return (
    <div style={{
      background:C.white, border:`1px solid ${C.line}`, borderRadius:10,
      padding:'14px 16px', position:'relative', overflow:'hidden'
    }}>
      {accent && <div style={{position:'absolute', top:0, left:0, width:3, height:'100%', background:accent}}/>}
      <div style={{display:'flex', justifyContent:'space-between', alignItems:'flex-start', marginBottom:8}}>
        <div style={{fontSize:11, color:C.mute, fontFamily:'"DM Mono", monospace', letterSpacing:0.8, textTransform:'uppercase'}}>{label}</div>
        <div style={{
          fontSize:10, padding:'2px 6px', borderRadius:4,
          background: deltaPos? '#E8F1E5' : '#F6E5E3',
          color: deltaPos? C.ok : C.bad,
          fontFamily:'"DM Mono", monospace', fontWeight:600
        }}>{deltaPos?'▲':'▼'} {delta}</div>
      </div>
      <div style={{display:'flex', alignItems:'baseline', gap:4, marginBottom:8}}>
        <div style={{fontSize:28, fontFamily:'"DM Serif Display", serif', color:C.navyDeep, lineHeight:1}}>{value}</div>
        {unit && <div style={{fontSize:13, color:C.mute}}>{unit}</div>}
      </div>
      {spark}
    </div>
  );
}

// =================== SPARKLINE ===================
function Spark({ data, color = C.gold, fill = true, h = 30 }) {
  const w = 100;
  const max = Math.max(...data), min = Math.min(...data);
  const pts = data.map((v,i) => `${(i/(data.length-1))*w},${h-((v-min)/(max-min||1))*(h-4)-2}`);
  const path = `M ${pts.join(' L ')}`;
  const area = `${path} L ${w},${h} L 0,${h} Z`;
  return (
    <svg viewBox={`0 0 ${w} ${h}`} width="100%" height={h} preserveAspectRatio="none">
      {fill && <path d={area} fill={color} opacity="0.12"/>}
      <path d={path} fill="none" stroke={color} strokeWidth="1.4"/>
    </svg>
  );
}

// =================== CARD WRAPPER ===================
const Card = ({ title, subtitle, action, children, pad = true, noBorder }) => (
  <div style={{
    background: C.white, border: noBorder?'none':`1px solid ${C.line}`, borderRadius:10,
    overflow:'hidden', display:'flex', flexDirection:'column'
  }}>
    {title && (
      <div style={{
        padding:'12px 16px', borderBottom:`1px solid ${C.lineSoft}`,
        display:'flex', alignItems:'center', justifyContent:'space-between'
      }}>
        <div>
          <div style={{fontSize:13, fontWeight:600, color:C.navyDeep, fontFamily:'"DM Serif Display", serif', letterSpacing:-0.1}}>{title}</div>
          {subtitle && <div style={{fontSize:11, color:C.mute, marginTop:2}}>{subtitle}</div>}
        </div>
        {action}
      </div>
    )}
    <div style={{flex:1, padding: pad?16:0}}>{children}</div>
  </div>
);

// =================== TABS ===================
const Tabs = ({ items, active = 0, onChange }) => (
  <div style={{display:'inline-flex', background:C.creamSoft, borderRadius:6, padding:2, border:`1px solid ${C.line}`}}>
    {items.map((it,i)=>(
      <button key={i} onClick={() => onChange && onChange(i)} style={{
        padding:'4px 10px', fontSize:11, border:'none', cursor:'pointer',
        borderRadius:4, fontWeight:600, fontFamily:'inherit',
        background: i===active ? C.white : 'transparent',
        color: i===active ? C.navyDeep : C.mute,
        boxShadow: i===active ? '0 1px 2px rgba(0,0,0,0.04)' : 'none'
      }}>{it}</button>
    ))}
  </div>
);

// =================== TOGGLE ===================
function Toggle({ on, onChange }) {
  return (
    <span onClick={() => onChange && onChange(!on)} style={{
      display:'inline-flex', width:28, height:16, borderRadius:10,
      background: on ? C.gold : C.line, position:'relative', cursor:'pointer', verticalAlign:'middle',
      transition:'background 0.2s'
    }}>
      <span style={{
        position:'absolute', top:2, left: on?14:2, width:12, height:12, borderRadius:'50%',
        background: on?C.navyDeep:C.white, boxShadow:'0 1px 2px rgba(0,0,0,0.2)', transition:'left 0.2s'
      }}/>
    </span>
  );
}

// =================== LOADING STATE ===================
function LoadingState({ rows = 4 }) {
  return (
    <div style={{padding:24}}>
      {Array.from({length: rows}).map((_,i) => (
        <div key={i} style={{
          height: 60, marginBottom: 12, borderRadius: 8,
          background: C.lineSoft, opacity: 0.7,
          animation: 'pulse 1.5s ease-in-out infinite'
        }}/>
      ))}
      <style>{`@keyframes pulse { 0%,100%{opacity:.7} 50%{opacity:.4} }`}</style>
    </div>
  );
}

// =================== ERROR BANNER ===================
function ErrorBanner({ msg }) {
  if (!msg) return null;
  return (
    <div style={{
      background:'#F6E5E3', color:C.bad, borderRadius:8, padding:'10px 16px',
      fontSize:12.5, margin:'16px 24px', border:`1px solid ${C.bad}22`
    }}>⚠ {msg}</div>
  );
}

// =================== NEW REQUEST MODAL ===================
function NewRequestModal({ onClose, agents, automations }) {
  const [form, setForm] = useState({name:'', email:'', phone:'', agent_id:'', automation_id:''});
  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState(false);
  const [error, setError] = useState('');
  const [queued, setQueued] = useState(false);

  const selectedAuto = (automations||[]).find(a => String(a.id) === String(form.automation_id));

  const submit = () => {
    if (!form.name || (!form.email && !form.phone)) {
      setError('Név és email vagy telefonszám szükséges'); return;
    }
    setLoading(true);
    apiFetch('api/contacts.php', {method:'POST', body: JSON.stringify({
      name: form.name, email: form.email, phone: form.phone,
      agent_id: form.agent_id || null,
      automation_id: form.automation_id || null,
    })})
    .then(c => {
      if (!c.id) throw new Error(c.error || 'Kontakt hiba');
      setQueued(!!c.review_request_queued);
      setSuccess(true);
    })
    .catch(e => setError(e.message))
    .finally(() => setLoading(false));
  };

  return (
    <div style={{position:'fixed',inset:0,background:'rgba(15,26,38,0.6)',zIndex:1000,display:'flex',alignItems:'center',justifyContent:'center'}}>
      <div style={{background:C.white,borderRadius:12,padding:28,width:480,maxWidth:'90vw',border:`1px solid ${C.line}`}}>
        <div style={{display:'flex',justifyContent:'space-between',marginBottom:20}}>
          <div style={{fontFamily:'"DM Serif Display",serif',fontSize:18,color:C.navyDeep}}>Új értékelés-kérés</div>
          <button onClick={onClose} style={{background:'transparent',border:'none',color:C.mute,cursor:'pointer',fontSize:18}}>×</button>
        </div>
        {success ? (
          <div style={{textAlign:'center',padding:'20px 0'}}>
            <div style={{fontSize:32,marginBottom:8,color:C.ok}}>✓</div>
            <div style={{fontWeight:600,color:C.navyDeep,marginBottom:6}}>Partner felvéve!</div>
            <div style={{fontSize:12,color:C.mute}}>
              {queued
                ? 'Értékelés-kérő üzenet sorba állítva — max. 15 percen belül kiküldve.'
                : 'Partner mentve. (Küldéshez válasszon automatizmust és ügynököt.)'}
            </div>
            <button onClick={onClose} style={{...pillBtn(true), marginTop:16, padding:'8px 20px'}}>Bezárás</button>
          </div>
        ) : (
          <>
            {error && <div style={{background:'#F6E5E3',color:C.bad,borderRadius:6,padding:'8px 12px',fontSize:12,marginBottom:14}}>{error}</div>}
            {[['Ügyfél neve','name','text',true],['Email cím','email','email',false],['Telefon (+36...)','phone','tel',false]].map(([lbl,key,type,req])=>(
              <div key={key} style={{marginBottom:12}}>
                <div style={{fontSize:11,color:C.mute,marginBottom:4}}>{lbl}{req?' *':''}</div>
                <input type={type} value={form[key]} onChange={e=>setForm(f=>({...f,[key]:e.target.value}))}
                  style={{width:'100%',padding:'8px 12px',borderRadius:6,border:`1px solid ${C.line}`,fontSize:13,fontFamily:'inherit',boxSizing:'border-box'}}/>
              </div>
            ))}
            <div style={{marginBottom:12}}>
              <div style={{fontSize:11,color:C.mute,marginBottom:4}}>Ügynök</div>
              <select value={form.agent_id} onChange={e=>setForm(f=>({...f,agent_id:e.target.value}))}
                style={{width:'100%',padding:'8px 12px',borderRadius:6,border:`1px solid ${C.line}`,fontSize:13,fontFamily:'inherit'}}>
                <option value="">— válasszon —</option>
                {(agents||[]).map(a=><option key={a.id} value={a.id}>{a.name}</option>)}
              </select>
            </div>
            <div style={{marginBottom:16}}>
              <div style={{fontSize:11,color:C.mute,marginBottom:4}}>Automatizmus</div>
              <select value={form.automation_id} onChange={e=>setForm(f=>({...f,automation_id:e.target.value}))}
                style={{width:'100%',padding:'8px 12px',borderRadius:6,border:`1px solid ${C.line}`,fontSize:13,fontFamily:'inherit'}}>
                <option value="">— azonnali küldés (alapértelmezett) —</option>
                {(automations||[]).filter(a=>a.active===1||a.active===true).map(a=>(
                  <option key={a.id} value={a.id}>{a.name}</option>
                ))}
              </select>
              {selectedAuto && (
                <div style={{marginTop:6,padding:'8px 12px',background:C.creamSoft,borderRadius:6,fontSize:11.5,color:C.navy,lineHeight:1.6}}>
                  <span style={{color:C.mute}}>Csatorna:</span> {selectedAuto.channel || '–'}
                  {selectedAuto.delay_hours > 0 && <> &nbsp;·&nbsp; <span style={{color:C.mute}}>Késleltetés:</span> {selectedAuto.delay_hours}ó</>}
                </div>
              )}
            </div>
            <div style={{display:'flex',gap:8,justifyContent:'flex-end'}}>
              <button onClick={onClose} style={{...pillBtn(false),padding:'8px 16px'}}>Mégse</button>
              <button onClick={submit} disabled={loading} style={{...pillBtn(true),padding:'8px 16px'}}>
                {loading ? 'Küldés...' : 'Küldés →'}
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

// =================== FUNNEL CHART ===================
function FunnelChart({ data }) {
  if (!data || data.length === 0) {
    return <div style={{padding:'30px 0', textAlign:'center', color:C.mute, fontSize:12}}>Nincs elegendő adat a grafikonhoz</div>;
  }
  const series = [
    { name:'Kérés',     color: C.navy,     key: 'requests' },
    { name:'Megnyitás', color: C.gold,     key: 'opened'   },
    { name:'Publikált', color: '#6E9C6E',  key: 'published'},
  ];
  const maxVal = Math.max(...data.flatMap(d => series.map(s => d[s.key] || 0)), 1);
  const H = 220, W = 720;
  const n = data.length;
  const totals = series.map(s => data.reduce((a, d) => a + (d[s.key] || 0), 0));
  return (
    <div>
      <div style={{display:'flex', gap:18, marginBottom:10}}>
        {series.map((s, i) => (
          <div key={s.name} style={{display:'flex', alignItems:'center', gap:6, fontSize:11.5, color:C.navy}}>
            <span style={{width:18, height:3, background:s.color, borderRadius:2, display:'inline-block'}}/>
            {s.name}
            <span style={{fontFamily:'"DM Mono", monospace', color:C.mute, marginLeft:2}}>{totals[i]}</span>
          </div>
        ))}
      </div>
      <svg viewBox={`0 0 ${W} ${H}`} width="100%" height={H}>
        {[0,1,2,3,4].map(i => (
          <g key={i}>
            <line x1="36" x2={W} y1={20 + i*45} y2={20 + i*45} stroke={C.lineSoft}/>
            <text x="0" y={24+i*45} fontSize="10" fill={C.mute} fontFamily="DM Mono, monospace">{Math.round(maxVal - i*(maxVal/4))}</text>
          </g>
        ))}
        {series.map((s, si) => {
          const pts = data.map((d, i) => {
            const v = d[s.key] || 0;
            return `${36 + (n === 1 ? (W-50)/2 : (i/(n-1))*(W-50))},${20 + (1 - v/maxVal)*180}`;
          });
          const path = `M ${pts.join(' L ')}`;
          return (
            <g key={si}>
              <path d={path} fill="none" stroke={s.color} strokeWidth="1.8"/>
              {data.map((d, i) => (i % Math.max(1, Math.floor(n/6)) === 0) && (
                <circle key={i}
                  cx={36 + (n === 1 ? (W-50)/2 : (i/(n-1))*(W-50))}
                  cy={20 + (1 - (d[s.key]||0)/maxVal)*180}
                  r="2.5" fill={C.white} stroke={s.color} strokeWidth="1.5"/>
              ))}
            </g>
          );
        })}
        {data.filter((_,i) => i % Math.max(1, Math.floor(n/7)) === 0).map((d, idx, arr) => {
          const origI = data.indexOf(d);
          return (
            <text key={idx}
              x={36 + (n === 1 ? (W-50)/2 : (origI/(n-1))*(W-50))}
              y={H-5} fontSize="9.5" fill={C.mute}
              fontFamily="DM Mono, monospace" textAnchor="middle">
              {(d.day || '').slice(5)}
            </text>
          );
        })}
      </svg>
    </div>
  );
}

// =================== CHANNEL BREAKDOWN ===================
function ChannelBreakdown({ data }) {
  const COLORS = [C.gold, C.navy, C.goldDeep, '#6E9C6E', C.mute, C.navySoft];
  const channels = (data || []).map((c, i) => ({
    name: c.channel || c.name || '?',
    value: c.pct || c.value || 0,
    count: c.count || 0,
    color: COLORS[i % COLORS.length],
  }));
  if (!channels.length) {
    return <div style={{padding:'20px 0', textAlign:'center', color:C.mute, fontSize:12}}>Nincs csatorna adat</div>;
  }
  const total = channels.reduce((a, c) => a + c.count, 0);
  let acc = 0;
  const R = 52, CX = 70, CY = 70;
  return (
    <div>
      <div style={{display:'flex', alignItems:'center', gap:20, marginBottom:16}}>
        <svg viewBox="0 0 140 140" width="140" height="140">
          <circle cx={CX} cy={CY} r={R} fill="none" stroke={C.lineSoft} strokeWidth="18"/>
          {channels.map((c, i) => {
            const pct = total > 0 ? c.count / total : c.value / 100;
            const len = pct * (2*Math.PI*R);
            const off = (acc) * (2*Math.PI*R);
            acc += pct;
            return (
              <circle key={i} cx={CX} cy={CY} r={R} fill="none"
                stroke={c.color} strokeWidth="18"
                strokeDasharray={`${len} ${2*Math.PI*R}`}
                strokeDashoffset={-off}
                transform={`rotate(-90 ${CX} ${CY})`}
              />
            );
          })}
          <text x={CX} y={CY-2} textAnchor="middle" fontSize="20" fontFamily="DM Serif Display, serif" fill={C.navyDeep}>{total}</text>
          <text x={CX} y={CY+12} textAnchor="middle" fontSize="9" fontFamily="DM Mono, monospace" fill={C.mute}>KÉRÉS · 30N</text>
        </svg>
        <div style={{flex:1, fontSize:11, color:C.mute}}>
          Összesen<br/>
          <span style={{color:C.navyDeep, fontWeight:600, fontSize:14, fontFamily:'"DM Serif Display", serif'}}>{total}</span><br/>
          kérés az utolsó 30 napban.
        </div>
      </div>
      <div style={{display:'flex', flexDirection:'column', gap:8}}>
        {channels.map(c => (
          <div key={c.name} style={{display:'flex', alignItems:'center', gap:10, fontSize:12}}>
            <span style={{width:6, height:6, borderRadius:'50%', background:c.color}}/>
            <span style={{color:C.navy, flex:1}}>{c.name}</span>
            <span style={{fontFamily:'"DM Mono", monospace', color:C.mute, fontSize:11}}>{c.value}%</span>
          </div>
        ))}
      </div>
    </div>
  );
}

// =================== AUTOMATION LIST ===================

function AutomationList({ automations, onToggle }) {
  const list = automations || [];
  return (
    <div style={{display:'flex', flexDirection:'column'}}>
      <div style={{display:'grid', gridTemplateColumns:'1.5fr 1fr 0.7fr 0.5fr 0.5fr 0.4fr', gap:10, padding:'4px 4px 8px', fontSize:10, fontFamily:'"DM Mono", monospace', color:C.mute, letterSpacing:0.8, borderBottom:`1px solid ${C.lineSoft}`}}>
        <div>NÉV</div><div>TRIGGER</div><div>CSATORNA</div><div style={{textAlign:'right'}}>FUTÁS</div><div style={{textAlign:'right'}}>KONV.</div><div style={{textAlign:'right'}}>ÁLLAPOT</div>
      </div>
      {list.map((a, i) => {
        const on      = a.active === 1 || a.active === true || a.on === true;
        const trigger = a.trigger_type || a.trigger || '–';
        const conv    = a.conv_count ?? (typeof a.conv === 'number' ? a.conv : null);
        const convStr = conv != null ? conv + '%' : (a.conv || '–');
        return (
        <div key={a.id ?? i} style={{display:'grid', gridTemplateColumns:'1.5fr 1fr 0.7fr 0.5fr 0.5fr 0.4fr', gap:10, padding:'10px 4px', fontSize:12, color:C.navy, alignItems:'center', borderBottom: i<list.length-1?`1px solid ${C.lineSoft}`:'none'}}>
          <div style={{display:'flex', alignItems:'center', gap:8}}>
            {a.alert && <span style={{width:6, height:6, borderRadius:'50%', background:C.bad}}/>}
            <span style={{fontWeight: on?600:500, color: on?C.navyDeep:C.mute}}>{a.name}</span>
          </div>
          <div style={{fontSize:11, color:C.mute, fontFamily:'"DM Mono", monospace'}}>{trigger}</div>
          <div>
            <span style={{fontSize:10.5, padding:'2px 6px', borderRadius:4, background:C.creamSoft, border:`1px solid ${C.line}`, color:C.navy, fontFamily:'"DM Mono", monospace'}}>{a.channel}</span>
          </div>
          <div style={{textAlign:'right', fontFamily:'"DM Mono", monospace'}}>{a.runs ?? 0}</div>
          <div style={{textAlign:'right', fontFamily:'"DM Mono", monospace', color: conv>65?C.ok:C.navyDeep, fontWeight:600}}>{convStr}</div>
          <div style={{textAlign:'right'}}>
            <Toggle on={on} onChange={onToggle ? () => onToggle(a.id ?? i) : undefined}/>
          </div>
        </div>
        );
      })}
    </div>
  );
}

// =================== REVIEW STREAM ===================
function ReviewStream({ reviews }) {
  const list = reviews || [];
  return (
    <div style={{display:'flex', flexDirection:'column', gap:0}}>
      {list.map((r,i) => {
        const author   = r.author || r.contact_name || '–';
        const stars    = r.stars ?? r.star_rating ?? r.star ?? 5;
        const initials = author.split(' ').filter(Boolean).map(w=>w[0]).join('').slice(0,2);
        return (
        <div key={i} style={{padding:'10px 4px', borderBottom: i<list.length-1?`1px solid ${C.lineSoft}`:'none', position:'relative'}}>
          {r.flagged && <div style={{position:'absolute', left:-16, top:14, width:3, height:'calc(100% - 28px)', background:C.bad, borderRadius:2}}/>}
          <div style={{display:'flex', alignItems:'center', gap:8, marginBottom:4}}>
            <div style={{width:22, height:22, borderRadius:'50%', background:r.flagged?'#F6E5E3':C.creamSoft, display:'flex', alignItems:'center', justifyContent:'center', fontSize:10, fontWeight:700, color:C.navyDeep, border:`1px solid ${C.line}`}}>
              {initials}
            </div>
            <span style={{fontSize:12, fontWeight:600, color:C.navyDeep}}>{author}</span>
            <Stars value={stars}/>
            <span style={{flex:1}}/>
            <span style={{fontSize:10.5, color:C.mute, fontFamily:'"DM Mono", monospace'}}>{r.time}</span>
          </div>
          <div style={{fontSize:11.5, color:C.navy, lineHeight:1.5, marginBottom:6, paddingLeft:30}}>{r.txt}</div>
          <div style={{paddingLeft:30, display:'flex', alignItems:'center', gap:6, fontSize:10, color:C.mute, fontFamily:'"DM Mono", monospace'}}>
            <Ico.tag width={10} height={10}/> {r.agent} · {r.loc}
            {r.flagged && <span style={{color:C.bad, marginLeft:6}}>· VÁLASZRA VÁR</span>}
          </div>
        </div>
        );
      })}
    </div>
  );
}

// =================== PROFILE TABLE ===================
const statusChip = (s) => {
  if (s==='top') return { bg:'#FAF1DF', fg:C.goldDeep, label:'KIEMELT' };
  if (s==='rising') return { bg:'#E8F1E5', fg:C.ok, label:'EMELKEDŐ' };
  if (s==='attention') return { bg:'#F6E5E3', fg:C.bad, label:'FIGYELEM' };
  return { bg:C.creamSoft, fg:C.mute, label:'STABIL' };
};

function ProfileTable({ agents, selectedAgent, onSelect }) {
  const list = agents || [];
  return (
    <div style={{margin:'-16px'}}>
      <div style={{display:'grid', gridTemplateColumns:'2fr 1fr 0.8fr 0.6fr 0.7fr 1.1fr 0.8fr 0.6fr', gap:10, padding:'10px 16px', fontSize:10, fontFamily:'"DM Mono", monospace', color:C.mute, letterSpacing:0.8, background:C.creamSoft, borderBottom:`1px solid ${C.line}`}}>
        <div>ÜGYNÖK</div><div>LOKÁCIÓ</div><div>ÁTLAG</div><div style={{textAlign:'right'}}>ÉRT.</div><div style={{textAlign:'right'}}>KÉRÉS</div><div>KONV. RÁTA</div><div>7 NAP</div><div style={{textAlign:'right'}}>ÁLLAPOT</div>
      </div>
      {list.map((a,i) => {
        const name    = a.name || a.agent_name || '–';
        const role    = a.role || a.trigger_type || '';
        const loc     = a.loc || a.office_name || '';
        const avgStar = a.avgStar ?? a.avg_star ?? 0;
        const conv    = a.conv ?? 0;
        const trend   = Array.isArray(a.trend) ? a.trend : [0,0,0,0,0,0,0];
        const chip    = statusChip(a.status);
        const initials = name.split(' ').filter(Boolean).map(w=>w[0]).join('').slice(0,2);
        return (
          <div key={i} onClick={() => onSelect && onSelect(a)} style={{display:'grid', gridTemplateColumns:'2fr 1fr 0.8fr 0.6fr 0.7fr 1.1fr 0.8fr 0.6fr', gap:10, padding:'10px 16px', fontSize:12, color:C.navy, alignItems:'center', borderBottom: i<list.length-1?`1px solid ${C.lineSoft}`:'none', background: (selectedAgent && (selectedAgent.id===a.id||selectedAgent.name===a.name)) ? '#FAF1DF' : C.white, cursor:'pointer', borderLeft: (selectedAgent && (selectedAgent.id===a.id||selectedAgent.name===a.name)) ? `3px solid ${C.gold}` : '3px solid transparent'}}>
            <div style={{display:'flex', alignItems:'center', gap:10}}>
              <div style={{width:30, height:30, borderRadius:'50%', background:`linear-gradient(135deg, ${C.gold}, ${C.goldDeep})`, display:'flex', alignItems:'center', justifyContent:'center', fontSize:10, fontWeight:700, color:C.navyDeep, flexShrink:0}}>
                {initials}
              </div>
              <div>
                <div style={{fontWeight:600, color:C.navyDeep}}>{name}</div>
                <div style={{fontSize:10.5, color:C.mute, fontFamily:'"DM Mono", monospace'}}>{role}</div>
              </div>
            </div>
            <div style={{fontSize:11.5}}>{loc}</div>
            <div style={{display:'flex', alignItems:'center', gap:6}}>
              <span style={{fontFamily:'"DM Serif Display", serif', fontSize:14, color:C.navyDeep}}>{avgStar}</span>
              <Stars value={Math.round(avgStar)} size={10}/>
            </div>
            <div style={{textAlign:'right', fontFamily:'"DM Mono", monospace', color:C.navyDeep}}>{a.reviews ?? 0}</div>
            <div style={{textAlign:'right', fontFamily:'"DM Mono", monospace'}}>{a.requests ?? 0}</div>
            <div style={{display:'flex', alignItems:'center', gap:8}}>
              <div style={{flex:1, height:5, background:C.lineSoft, borderRadius:3, overflow:'hidden'}}>
                <div style={{width:`${conv}%`, height:'100%', background: conv>65?C.gold : conv>50?C.goldSoft : C.bad, borderRadius:3}}/>
              </div>
              <span style={{fontSize:10.5, fontFamily:'"DM Mono", monospace', color:C.mute, width:24, textAlign:'right'}}>{conv}%</span>
            </div>
            <div style={{height:24, width:'100%'}}>
              <Spark data={trend} color={a.status==='attention'?C.bad:C.gold} fill={false} h={24}/>
            </div>
            <div style={{textAlign:'right'}}>
              <span style={{fontSize:9.5, padding:'3px 7px', borderRadius:4, background:chip.bg, color:chip.fg, fontFamily:'"DM Mono", monospace', fontWeight:700, letterSpacing:0.5}}>{chip.label}</span>
            </div>
          </div>
        );
      })}
    </div>
  );
}

// =================== AGENT DETAIL ===================
function AgentDetail({ agent }) {
  if (!agent) return <div style={{padding:16, color:C.mute, fontSize:13}}>Válassz ügynököt a listából.</div>;
  const name = agent.name || agent.agent_name || '–';
  const initials = name.split(' ').filter(Boolean).map(w=>w[0]).join('').slice(0,2).toUpperCase();
  const role = agent.role || '–';
  const office = agent.office_name || '–';
  const phone = agent.phone || '–';
  const email = agent.email || '–';
  const reviewLink = agent.review_link || '';
  const signature = agent.signature || agent.personalized_msg || '';
  return (
    <div>
      <div style={{display:'flex', gap:14, alignItems:'center', marginBottom:16, paddingBottom:14, borderBottom:`1px solid ${C.lineSoft}`}}>
        <div style={{width:64, height:64, borderRadius:'50%', background:`linear-gradient(135deg, ${C.gold}, ${C.goldDeep})`, display:'flex', alignItems:'center', justifyContent:'center', fontWeight:700, color:C.navyDeep, fontSize:22, fontFamily:'"DM Serif Display", serif'}}>{initials}</div>
        <div style={{flex:1}}>
          <div style={{fontFamily:'"DM Serif Display", serif', fontSize:18, color:C.navyDeep}}>{name}</div>
          <div style={{fontSize:11.5, color:C.mute}}>{role} · {office}</div>
          <div style={{marginTop:6, display:'flex', gap:6}}>
            <span style={{fontSize:10, padding:'2px 6px', borderRadius:4, background: agent.status==='active'?'#E8F1E5':'#F6E5E3', color: agent.status==='active'?C.ok:C.bad, fontFamily:'"DM Mono", monospace', fontWeight:700}}>{agent.status==='active'?'AKTÍV':'INAKTÍV'}</span>
          </div>
        </div>
      </div>
      <div style={{display:'flex', flexDirection:'column', gap:10, fontSize:12}}>
        {reviewLink && <Field label="Google értékelés-link" value={reviewLink} mono action="Másol" onAction={() => navigator.clipboard?.writeText(reviewLink).then(() => alert('Link vágólapra másolva!'))}/>}
        {email && email !== '–' && <Field label="Email" value={email} mono/>}
        {phone && phone !== '–' && <Field label="Telefonszám" value={phone} mono/>}
        <Field label="Ügynök ID" value={'#' + agent.id} mono/>
      </div>
      {signature && (
        <div style={{marginTop:14, padding:12, borderRadius:8, background:C.creamSoft, border:`1px solid ${C.line}`}}>
          <div style={{fontSize:11, fontFamily:'"DM Mono", monospace', color:C.mute, letterSpacing:0.6, marginBottom:8}}>SZEMÉLYESÍTETT ÜZENET</div>
          <div style={{fontSize:12, color:C.navy, lineHeight:1.6, fontStyle:'italic'}}>„{signature}"</div>
        </div>
      )}
    </div>
  );
}

// =================== AGENT GOALS ===================
function AgentGoals({ agent }) {
  if (!agent) return <div style={{padding:16, color:C.mute, fontSize:13}}>Válassz ügynököt a listából.</div>;
  const name = (agent.name || agent.agent_name || '').split(' ')[0];
  const reviews  = agent.reviews  ?? 0;
  const requests = agent.requests ?? 0;
  const avgStar  = parseFloat(agent.avg_star ?? agent.avgStar ?? 0);
  const conv     = parseFloat(agent.conv ?? 0);
  const convPct  = Math.min(Math.round((conv / 70) * 100), 150);
  const starPct  = avgStar > 0 ? Math.round((avgStar / 5) * 100) : 0;
  const reqPct   = requests > 0 ? Math.min(Math.round((reviews / requests) * 100), 100) : 0;
  const goals = [
    { label:'Elküldött kérések', current:requests, target:'–', color:C.navy, pct: Math.min(requests*5, 100) },
    { label:'Beérkezett értékelések', current:reviews, target:'–', color:C.gold, pct: reqPct },
    { label:'Átlag csillag', current:avgStar||'–', target:'5', color:C.ok, unit:'/5', pct: starPct },
    { label:'Konverziós ráta', current:conv+'%', target:'70%', color:C.ok, pct: convPct },
  ];
  return (
    <div>
      <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap:14, marginBottom:14}}>
        {goals.map((g,i) => (
          <div key={i}>
            <div style={{display:'flex', justifyContent:'space-between', marginBottom:6}}>
              <span style={{fontSize:11.5, color:C.mute}}>{g.label}</span>
              <span style={{fontSize:11, fontFamily:'"DM Mono", monospace', color: g.pct>=100?C.ok:C.navy}}>{g.pct}%</span>
            </div>
            <div style={{display:'flex', alignItems:'baseline', gap:4, marginBottom:6}}>
              <span style={{fontFamily:'"DM Serif Display", serif', fontSize:24, color:C.navyDeep}}>{g.current}</span>
              {g.unit && <span style={{fontSize:11, color:C.mute}}>{g.unit}</span>}
              <span style={{fontSize:10.5, color:C.mute, fontFamily:'"DM Mono", monospace', marginLeft:6}}>/ cél {g.target}{g.unit||''}</span>
            </div>
            <div style={{height:6, background:C.lineSoft, borderRadius:3, overflow:'hidden'}}>
              <div style={{width:`${Math.min(g.pct,100)}%`, height:'100%', background:g.color, borderRadius:3}}/>
            </div>
          </div>
        ))}
      </div>
      <div style={{padding:'12px 14px', borderRadius:8, background:C.navyDeep, color:C.cream, display:'flex', alignItems:'center', gap:14}}>
        <div style={{flex:1}}>
          <div style={{fontSize:11, color:C.goldSoft, fontFamily:'"DM Mono", monospace', letterSpacing:0.6, marginBottom:2}}>TELJESÍTMÉNY</div>
          <div style={{fontSize:14, fontFamily:'"DM Serif Display", serif'}}>{name ? name + ' · ' : ''}{reviews} értékelés, {requests} kérésből</div>
        </div>
        <div style={{fontFamily:'"DM Serif Display", serif', fontSize:28, color:C.gold}}>{reqPct}%</div>
      </div>
    </div>
  );
}

// =================== FIELD HELPER ===================
const Field = ({ label, value, mono, action, onAction }) => (
  <div style={{display:'flex', justifyContent:'space-between', alignItems:'center', padding:'8px 0', borderBottom:`1px solid ${C.lineSoft}`}}>
    <div style={{fontSize:11, color:C.mute}}>{label}</div>
    <div style={{display:'flex', alignItems:'center', gap:8}}>
      <span style={{fontSize:11.5, color:C.navyDeep, fontFamily: mono?'"DM Mono", monospace':'inherit', fontWeight: mono?500:600}}>{value}</span>
      {action && <button onClick={onAction} style={{...pillBtn(false), padding:'3px 8px', fontSize:10}}>{action}</button>}
    </div>
  </div>
);

// =================== LEGEND HELPER ===================
const Legend = ({c,l}) => <span style={{display:'inline-flex', alignItems:'center', gap:6, color:C.navy}}><span style={{width:10, height:10, background:c, borderRadius:2}}/>{l}</span>;

// =================== HEATMAP ===================
function Heatmap() {
  const days = ['H','K','Sze','Cs','P','Szo','V'];
  const hours = [9,10,11,12,13,14,15,16,17,18,19,20];
  return (
    <div>
      <div style={{display:'grid', gridTemplateColumns:`24px repeat(${hours.length}, 1fr)`, gap:3, fontSize:9.5, fontFamily:'"DM Mono", monospace', color:C.mute}}>
        <div/>
        {hours.map(h => <div key={h} style={{textAlign:'center'}}>{h}</div>)}
        {days.map((d,di) => (
          <React.Fragment key={d}>
            <div style={{display:'flex', alignItems:'center', justifyContent:'center', color:C.navy}}>{d}</div>
            {hours.map((h,hi) => {
              const val = Math.max(0, Math.sin((hi-2)*0.5) * 0.5 + 0.5 - (di>=5?0.3:0) + (h===15||h===16?0.3:0) - Math.random()*0.1);
              const a = Math.min(1, val);
              return <div key={hi} style={{aspectRatio:'1', background: a<0.1?C.lineSoft:`rgba(184,147,90,${a})`, borderRadius:2}}/>;
            })}
          </React.Fragment>
        ))}
      </div>
      <div style={{display:'flex', alignItems:'center', gap:6, marginTop:14, fontSize:10.5, color:C.mute, fontFamily:'"DM Mono", monospace'}}>
        Kevés
        {[0.1,0.3,0.5,0.7,0.9].map(a => <span key={a} style={{width:14, height:10, background:`rgba(184,147,90,${a})`, borderRadius:2}}/>)}
        Sok
        <span style={{flex:1}}/>
        Csúcs: <span style={{color:C.navyDeep, fontWeight:700}}>Sze 15:00 · K 14:00</span>
      </div>
    </div>
  );
}

// =================== OFFICE COMPARE ===================
function OfficeCompare({ offices }) {
  const COLORS = [C.gold, C.goldDeep, C.navy, C.navySoft, C.mute];
  const raw = offices || [];
  const list = raw.map((o, i) => ({
    name: o.office_name || o.name || '?',
    reviews: o.reviews ?? 0,
    avg: parseFloat(o.avg_star || o.avg || 0).toFixed(2),
    conv: parseFloat(o.conv_rate || o.conv || 0).toFixed(1),
    color: COLORS[i % COLORS.length],
  }));
  if (!list.length) {
    return <div style={{padding:'20px 0', textAlign:'center', color:C.mute, fontSize:12}}>Nincs iroda adat</div>;
  }
  const maxReviews = Math.max(...list.map(o => o.reviews), 1);
  return (
    <div style={{display:'flex', flexDirection:'column', gap:14}}>
      {list.map(o => (
        <div key={o.name}>
          <div style={{display:'flex', justifyContent:'space-between', marginBottom:5, alignItems:'baseline'}}>
            <span style={{fontSize:12.5, fontWeight:600, color:C.navyDeep}}>{o.name}</span>
            <span style={{fontSize:11, fontFamily:'"DM Mono", monospace', color:C.mute}}>
              <span style={{color:C.navyDeep, fontWeight:600}}>{o.reviews}</span> ért. · <span style={{color:C.navyDeep, fontWeight:600}}>{o.avg}★</span> · <span style={{color:C.navyDeep, fontWeight:600}}>{o.conv}%</span> konv.
            </span>
          </div>
          <div style={{display:'flex', gap:4}}>
            <div style={{flex:o.reviews, height:6, background:o.color, borderRadius:3}}/>
            <div style={{flex:maxReviews-o.reviews, height:6, background:C.lineSoft, borderRadius:3}}/>
          </div>
        </div>
      ))}
    </div>
  );
}

// =================== KEYWORD CLOUD ===================
function KeywordCloud() {
  const kws = [
    { w:'profi', s:48 }, { w:'gyors', s:42 }, { w:'megbízható', s:36 }, { w:'segítőkész', s:32 },
    { w:'kommunikáció', s:28 }, { w:'korrekt', s:34 }, { w:'türelmes', s:22 }, { w:'ajánlom', s:38 },
    { w:'rugalmas', s:18 }, { w:'pontos', s:24 }, { w:'felkészült', s:20 }, { w:'barátságos', s:26 },
    { w:'tapasztalt', s:22 }, { w:'álomotthon', s:14 }, { w:'köszönöm', s:16 }, { w:'figyelmes', s:18 },
  ];
  return (
    <div style={{display:'flex', flexWrap:'wrap', gap:8, alignItems:'center', padding:'10px 0'}}>
      {kws.map(k => (
        <span key={k.w} style={{
          fontFamily:'"DM Serif Display", serif',
          fontSize: 12 + k.s*0.6,
          color: k.s>30 ? C.goldDeep : k.s>20 ? C.navy : C.mute,
          lineHeight:1.1,
          padding:'2px 4px'
        }}>{k.w}</span>
      ))}
    </div>
  );
}

// =================== COMPETITOR BAR ===================
function CompetitorBar() {
  const comps = [
    { name:'Fodor Ingatlan', stars:4.86, reviews:127, you:true },
    { name:'OttHonRez Ingatlan', stars:4.72, reviews:212 },
    { name:'BudaHome Kft.', stars:4.65, reviews:184 },
    { name:'Várnegyed Ingatlan', stars:4.51, reviews:96 },
    { name:'Margit Real Estate', stars:4.43, reviews:78 },
    { name:'Belváros Plus', stars:4.21, reviews:142 },
  ];
  const max = 250;
  return (
    <div style={{display:'flex', flexDirection:'column', gap:10}}>
      {comps.map(c => (
        <div key={c.name} style={{display:'grid', gridTemplateColumns:'200px 1fr 80px 70px', gap:14, alignItems:'center'}}>
          <div style={{fontSize:12.5, fontWeight: c.you?700:500, color: c.you?C.goldDeep:C.navyDeep, display:'flex', alignItems:'center', gap:6}}>
            {c.you && <Ico.dot style={{color:C.gold}}/>}
            {c.name}
            {c.you && <span style={{fontSize:9, padding:'1px 5px', borderRadius:3, background:C.gold, color:C.navyDeep, fontFamily:'"DM Mono", monospace', fontWeight:700}}>TE</span>}
          </div>
          <div style={{height:14, background:C.lineSoft, borderRadius:7, overflow:'hidden'}}>
            <div style={{width: `${(c.reviews/max)*100}%`, height:'100%', background: c.you?C.gold:C.navySoft, borderRadius:7, display:'flex', alignItems:'center', justifyContent:'flex-end', paddingRight:8}}>
              <span style={{fontSize:10, color:'#fff', fontFamily:'"DM Mono", monospace', fontWeight:600}}>{c.reviews}</span>
            </div>
          </div>
          <div style={{display:'flex', alignItems:'center', gap:4, justifyContent:'flex-end'}}>
            <span style={{fontFamily:'"DM Serif Display", serif', fontSize:14, color:C.navyDeep}}>{c.stars}</span>
            <Ico.star style={{color:C.gold}} width={11} height={11}/>
          </div>
          <div style={{fontSize:11, fontFamily:'"DM Mono", monospace', color:C.mute, textAlign:'right'}}>
            {c.you ? 'cél: 4.90' : '—'}
          </div>
        </div>
      ))}
    </div>
  );
}

// =================== STACKED BARS ===================
function StackedBars() {
  const months = ['Jún','Júl','Aug','Szept','Okt','Nov','Dec','Jan','Febr','Márc','Ápr','Máj'];
  const data = months.map((m,i) => ({
    m,
    s5: 32 + Math.sin(i*0.6)*4 + i*1.5,
    s4: 12 + Math.cos(i*0.4)*2,
    s3: 3 + Math.sin(i*0.3),
    s2: 1.5 + Math.cos(i*0.5)*0.6,
    s1: 0.8 + Math.sin(i*0.7)*0.4
  }));
  const max = 60;
  return (
    <div>
      <div style={{display:'flex', gap:14, marginBottom:10, fontSize:11}}>
        <Legend c={C.gold} l="5★"/><Legend c={C.goldSoft} l="4★"/><Legend c="#C2B7A1" l="3★"/><Legend c="#9F857A" l="2★"/><Legend c={C.bad} l="1★"/>
      </div>
      <svg viewBox="0 0 720 240" width="100%" height="240">
        {[0,1,2,3].map(i => <line key={i} x1="40" x2="720" y1={20+i*50} y2={20+i*50} stroke={C.lineSoft}/>)}
        {[0,1,2,3].map(i => <text key={i} x="0" y={24+i*50} fontSize="9.5" fill={C.mute} fontFamily="DM Mono, monospace">{60-i*15}</text>)}
        {data.map((d,i) => {
          const x = 50 + i*55;
          const w = 30;
          let y = 220;
          const segs = [
            { v:d.s1, c:C.bad },
            { v:d.s2, c:'#9F857A' },
            { v:d.s3, c:'#C2B7A1' },
            { v:d.s4, c:C.goldSoft },
            { v:d.s5, c:C.gold },
          ];
          return (
            <g key={i}>
              {segs.map((s,si) => {
                const h = (s.v/max) * 200;
                y -= h;
                return <rect key={si} x={x} y={y} width={w} height={h-1} fill={s.c} rx="1"/>;
              })}
              <text x={x+w/2} y="234" fontSize="9.5" fill={C.mute} fontFamily="DM Mono, monospace" textAnchor="middle">{d.m}</text>
            </g>
          );
        })}
      </svg>
    </div>
  );
}

// =================== AUTOMATION BUILDER ===================
function AutomationBuilder({ automation }) {
  const w = useWindowWidth();
  const isMobile = w < 768;

  if (!automation) {
    return (
      <div style={{padding:'32px 0', textAlign:'center', color:C.mute, fontSize:13, display:'flex', flexDirection:'column', alignItems:'center', gap:10}}>
        <div style={{width:44, height:44, borderRadius:12, background:C.creamSoft, border:`1.5px dashed ${C.line}`, display:'flex', alignItems:'center', justifyContent:'center', color:C.mute}}>
          <Ico.bolt width={20} height={20}/>
        </div>
        <span>Kattintson egy automatizmusra a listában az áttekintéshez</span>
      </div>
    );
  }

  let cfg = {};
  try { cfg = JSON.parse(automation.trigger_config || '{}'); } catch(e) {}

  const triggerLabels = {
    adásvétel:'Adásvétel lezárása', bérleti_aláírás:'Bérleti aláírás',
    megtekintés:'Megtekintés', ünnep:'Ünnepi esemény', inaktív:'Inaktív ügyfél', egyéb:'Egyéb trigger',
  };
  const channelLabel = { email:'Email', sms:'SMS', 'email+sms':'Email + SMS' };
  const channelIcon  = { email:'✉', sms:'📱', 'email+sms':'✉+📱' };

  const delayH   = automation.delay_hours || 0;
  const delayStr = delayH === 0 ? 'Azonnal' : delayH % 24 === 0 ? `${delayH/24} nap múlva` : `${delayH} óra múlva`;

  const TONES = {
    navy:  { accent: C.navy,    bg: C.creamSoft,   fg: C.navyDeep },
    ok:    { accent: C.ok,      bg: '#E8F1E5',      fg: '#2A6040'  },
    gold:  { accent: C.goldDeep,bg: '#FAF1DF',      fg: C.goldDeep },
    bad:   { accent: C.bad,     bg: '#F6E5E3',      fg: C.bad      },
  };

  const steps = [];

  steps.push({
    tone: 'navy',
    label: 'TRIGGER',
    icon: '⚡',
    title: triggerLabels[automation.trigger_type] || automation.trigger_type || '–',
    meta: delayStr,
    detail: `#${automation.id}`,
  });

  steps.push({
    tone: 'ok',
    label: channelLabel[automation.channel] || 'ÜZENET',
    icon: channelIcon[automation.channel] || '✉',
    title: 'Üzenet kiküldése',
    meta: automation.template_name ? `„${automation.template_name}"` : automation.template_id ? `Sablon #${automation.template_id}` : 'Sablon hiányzik',
    detail: channelLabel[automation.channel] || '–',
  });

  if (cfg.reminder_enabled) {
    steps.push({
      tone: 'gold',
      label: 'EMLÉKEZTETŐ',
      icon: '🔔',
      title: 'Emlékeztető',
      meta: `${cfg.reminder_days || 3} nap után`,
      detail: 'Ha nem kattintott',
    });
  }

  const isActive = automation.active === 1 || automation.active === true;
  steps.push({
    tone: isActive ? 'ok' : 'bad',
    label: 'VISSZAELLENŐRZÉS',
    icon: isActive ? '✓' : '✕',
    title: isActive ? 'Figyelés · 7 nap' : 'Inaktív',
    meta: isActive ? `${automation.runs || 0} futás · ${automation.conv_count || 0} konv.` : 'Ki van kapcsolva',
    detail: isActive ? 'Publikálásra vár' : 'Nem fut',
  });

  // Vertical connector between steps
  const Arrow = () => isMobile
    ? <div style={{display:'flex', justifyContent:'center', margin:'0 0 0 20px'}}>
        <div style={{width:2, height:20, background:C.line}}/>
      </div>
    : <div style={{display:'flex', alignItems:'center', flexShrink:0, padding:'0 4px'}}>
        <div style={{width:28, height:2, background:C.line}}/>
        <div style={{width:0, height:0, borderTop:'5px solid transparent', borderBottom:'5px solid transparent', borderLeft:`6px solid ${C.line}`}}/>
      </div>;

  return (
    <div style={{padding: isMobile ? '8px 0' : '12px 4px'}}>
      {/* Stats bar */}
      <div style={{display:'flex', gap:12, marginBottom:16, padding:'10px 14px', background:C.creamSoft, borderRadius:8, border:`1px solid ${C.lineSoft}`}}>
        <div style={{display:'flex', gap:4, alignItems:'center'}}>
          <div style={{width:7, height:7, borderRadius:'50%', background: isActive ? C.ok : C.bad}}/>
          <span style={{fontSize:11, color:C.mute, fontFamily:'"DM Mono", monospace'}}>{isActive ? 'AKTÍV' : 'INAKTÍV'}</span>
        </div>
        <div style={{width:1, background:C.line}}/>
        <span style={{fontSize:11, color:C.mute}}>{triggerLabels[automation.trigger_type] || automation.trigger_type || '–'}</span>
        <div style={{width:1, background:C.line}}/>
        <span style={{fontSize:11, color:C.mute}}>{steps.length} lépés</span>
        <div style={{flex:1}}/>
        <span style={{fontSize:11, color:C.navyDeep, fontWeight:600}}>{automation.runs || 0} futás</span>
        <span style={{fontSize:11, color:C.ok, fontWeight:600}}>{automation.conv_count || 0} konverzió</span>
      </div>

      {/* Flow steps */}
      <div style={{
        display: 'flex',
        flexDirection: isMobile ? 'column' : 'row',
        alignItems: isMobile ? 'stretch' : 'flex-start',
        gap: 0,
        overflowX: isMobile ? 'visible' : 'auto',
        paddingBottom: isMobile ? 0 : 4,
      }}>
        {steps.map((s, i) => {
          const t = TONES[s.tone] || TONES.navy;
          return (
            <React.Fragment key={i}>
              <div style={{
                flex: isMobile ? 'none' : '1 1 0',
                minWidth: isMobile ? 'auto' : 140,
                maxWidth: isMobile ? 'none' : 240,
                background: C.white,
                border: `1px solid ${C.line}`,
                borderRadius: 10,
                overflow: 'hidden',
                boxShadow: '0 1px 4px rgba(31,45,61,0.06)',
              }}>
                {/* Colored top bar */}
                <div style={{height:4, background: t.accent}}/>
                <div style={{padding:'12px 14px'}}>
                  {/* Step number + label */}
                  <div style={{display:'flex', alignItems:'center', gap:6, marginBottom:8}}>
                    <div style={{width:20, height:20, borderRadius:5, background: t.accent, color:'#fff', display:'flex', alignItems:'center', justifyContent:'center', fontSize:10, fontWeight:700, fontFamily:'"DM Mono", monospace', flexShrink:0}}>{i+1}</div>
                    <span style={{fontSize:9, fontFamily:'"DM Mono", monospace', color: t.fg, letterSpacing:0.8, fontWeight:700}}>{s.label}</span>
                  </div>
                  {/* Icon + title */}
                  <div style={{display:'flex', gap:8, alignItems:'flex-start', marginBottom:6}}>
                    <div style={{width:32, height:32, borderRadius:8, background: t.bg, display:'flex', alignItems:'center', justifyContent:'center', fontSize:15, flexShrink:0}}>{s.icon}</div>
                    <div style={{flex:1}}>
                      <div style={{fontSize:12.5, fontWeight:700, color:C.navyDeep, lineHeight:1.3}}>{s.title}</div>
                      <div style={{fontSize:10.5, color:C.mute, marginTop:2, lineHeight:1.4}}>{s.meta}</div>
                    </div>
                  </div>
                  {/* Detail badge */}
                  <div style={{marginTop:4}}>
                    <span style={{fontSize:9.5, padding:'2px 7px', borderRadius:4, background: t.bg, color: t.fg, fontFamily:'"DM Mono", monospace', fontWeight:600}}>{s.detail}</span>
                  </div>
                </div>
              </div>
              {i < steps.length - 1 && <Arrow/>}
            </React.Fragment>
          );
        })}
      </div>
    </div>
  );
}

// =================== RECIPE LIST ===================
function RecipeList({ onApply }) {
  const recipes = [
    { key:'pozitív', name:'Adásvétel után · azonnal', desc:'Sikeres szerződés zárása után', icon:'check', hot:true, trigger:'adásvétel', channel:'email', delay:0 },
    { key:'bérleti', name:'Bérleti aláírás után', desc:'Mindkét fél részére', icon:'check', trigger:'bérleti_aláírás', channel:'sms', delay:4 },
    { key:'megtekintés', name:'Megtekintés után · 3 nap', desc:'Akkor is ha nem vásárolt', icon:'user', trigger:'megtekintés', channel:'email', delay:72 },
    { key:'ünnep', name:'Karácsonyi ünnepi', desc:'Évzáró felhívás', icon:'mail', trigger:'ünnep', channel:'email', delay:0 },
    { key:'inaktív', name:'Inaktív ügyfél · 6 hó', desc:'Régi kapcsolat aktiválás', icon:'bolt', trigger:'inaktív', channel:'email', delay:0 },
  ];
  return (
    <div style={{display:'flex', flexDirection:'column', gap:6}}>
      {recipes.map((r,i) => {
        const I = Ico[r.icon];
        return (
          <div key={i} onClick={() => onApply && onApply(r)} style={{display:'flex', alignItems:'center', gap:10, padding:'10px 12px', border:`1px solid ${C.lineSoft}`, borderRadius:8, cursor:'pointer', background: r.hot?'#FAF1DF':C.white}}>
            <div style={{width:30, height:30, borderRadius:6, background:r.hot?C.gold:C.creamSoft, color:r.hot?C.navyDeep:C.navy, display:'flex', alignItems:'center', justifyContent:'center'}}>
              <I/>
            </div>
            <div style={{flex:1}}>
              <div style={{fontSize:12.5, fontWeight:600, color:C.navyDeep, display:'flex', gap:6, alignItems:'center'}}>
                {r.name}
                {r.hot && <span style={{fontSize:9, padding:'1px 5px', borderRadius:3, background:C.gold, color:C.navyDeep, fontFamily:'"DM Mono", monospace', fontWeight:700}}>NÉPSZERŰ</span>}
              </div>
              <div style={{fontSize:11, color:C.mute}}>{r.desc}</div>
            </div>
            <Ico.arrow style={{color:C.mute}}/>
          </div>
        );
      })}
    </div>
  );
}

// =================== AUTOMATION FULL LIST ===================
function AutomationFullList({ automations, onToggle, onEdit, onSelect }) {
  const list = automations || [];
  return (
    <div>
      <div style={{display:'grid', gridTemplateColumns:'40px 1.5fr 1fr 0.7fr 0.5fr 0.5fr 0.6fr', gap:10, padding:'10px 16px', fontSize:10, fontFamily:'"DM Mono", monospace', color:C.mute, letterSpacing:0.8, background:C.creamSoft, borderBottom:`1px solid ${C.line}`}}>
        <div></div><div>NÉV</div><div>TRIGGER</div><div>CSATORNA</div><div style={{textAlign:'right'}}>FUTÁS · 30N</div><div style={{textAlign:'right'}}>KONV.</div><div style={{textAlign:'right'}}>ÁLLAPOT</div>
      </div>
      {list.map((a,i)=>{
        const on      = a.active === 1 || a.active === true || a.on === true;
        const trigger = a.trigger_type || a.trigger || '–';
        const conv    = a.conv_count ?? (typeof a.conv === 'number' ? a.conv : null);
        const convStr = conv != null ? conv + '%' : (a.conv || '–');
        return (
        <div key={a.id ?? i} onClick={() => onSelect && onSelect(a)} style={{display:'grid', gridTemplateColumns:'40px 1.5fr 1fr 0.7fr 0.5fr 0.5fr 0.6fr', gap:10, padding:'12px 16px', fontSize:12, alignItems:'center', borderBottom: i<list.length-1?`1px solid ${C.lineSoft}`:'none', background: on?C.white:C.creamSoft, cursor: onSelect ? 'pointer' : 'default'}}>
          <div style={{display:'flex', justifyContent:'center'}}>
            <div style={{width:28, height:28, borderRadius:6, background: on?C.creamSoft:C.lineSoft, display:'flex', alignItems:'center', justifyContent:'center', color: on?C.navy:C.mute}}>
              <Ico.bolt/>
            </div>
          </div>
          <div>
            <div style={{fontWeight:600, color: on?C.navyDeep:C.mute, display:'flex', gap:6, alignItems:'center'}}>
              {a.name}
            </div>
            <div style={{fontSize:10.5, color:C.mute, fontFamily:'"DM Mono", monospace', marginTop:2}}>#{a.id ?? i+1}</div>
          </div>
          <div style={{fontSize:11, color:C.mute, fontFamily:'"DM Mono", monospace'}}>{trigger}</div>
          <div>
            <span style={{fontSize:10.5, padding:'2px 6px', borderRadius:4, background:C.creamSoft, border:`1px solid ${C.line}`, color:C.navy, fontFamily:'"DM Mono", monospace'}}>{a.channel}</span>
          </div>
          <div style={{textAlign:'right', fontFamily:'"DM Mono", monospace'}}>{a.runs ?? 0}</div>
          <div style={{textAlign:'right', fontFamily:'"DM Mono", monospace', color: conv>50?C.ok:C.navyDeep, fontWeight:600}}>{convStr}</div>
          <div style={{display:'flex', justifyContent:'flex-end', gap:8, alignItems:'center'}}>
            <Toggle on={on} onChange={onToggle ? () => onToggle(a.id ?? i) : undefined}/>
            <button onClick={() => onEdit && onEdit(a)} style={{background:'transparent', border:'none', cursor:'pointer', color:C.mute}}><Ico.gear width={14} height={14}/></button>
          </div>
        </div>
        );
      })}
    </div>
  );
}

// =================== FLAGGED LIST ===================
function FlaggedList({ items, onReply, onAssign }) {
  const list = items || [];
  return (
    <div style={{display:'flex', flexDirection:'column', gap:10}}>
      {list.map((f,i) => {
        const name = f.author || f.contact_name || '–';
        const star = f.star ?? f.star_rating ?? 0;
        const slaColor = f.sla_status==='breach' || f.sla==='breach' ? C.bad : f.sla_status==='sla-due' || f.sla==='sla-due' ? C.warn : C.ok;
        const slaLabel = slaColor===C.bad ? 'SLA SÉRTÉS' : slaColor===C.warn ? 'SLA KÖZEL' : 'IDŐBEN';
        return (
          <div key={i} style={{border:`1px solid ${C.lineSoft}`, borderLeft:`3px solid ${slaColor}`, borderRadius:8, padding:'12px 14px', background:C.creamSoft}}>
            <div style={{display:'flex', alignItems:'center', gap:8, marginBottom:6}}>
              <div style={{width:26, height:26, borderRadius:'50%', background:C.white, border:`1px solid ${C.line}`, display:'flex', alignItems:'center', justifyContent:'center', fontSize:10, fontWeight:700, color:C.navyDeep}}>
                {name.split(' ').filter(Boolean).map(w=>w[0]).join('').slice(0,2)}
              </div>
              <span style={{fontSize:12.5, fontWeight:600, color:C.navyDeep}}>{name}</span>
              <Stars value={star}/>
              <span style={{flex:1}}/>
              <span style={{fontSize:9.5, padding:'2px 6px', borderRadius:4, background:slaColor, color:'#fff', fontFamily:'"DM Mono", monospace', fontWeight:700}}>{slaLabel}</span>
            </div>
            <div style={{fontSize:11.5, color:C.navy, lineHeight:1.5, marginBottom:8, paddingLeft:34}}>"{f.msg || f.msg_excerpt || ''}"</div>
            <div style={{paddingLeft:34, display:'flex', alignItems:'center', gap:6}}>
              <span style={{fontSize:10, color:C.mute, fontFamily:'"DM Mono", monospace'}}>VÁRAKOZÁS: {f.wait || f.wait_duration || '—'} · ÜGYNÖK: {f.agent || f.agent_name || '—'}</span>
              <span style={{flex:1}}/>
              <button onClick={() => onAssign && onAssign(f)} style={{...pillBtn(false), padding:'4px 8px', fontSize:10.5}}>Hozzárendel</button>
              <button onClick={() => onReply && onReply(f)} style={{...pillBtn(true), padding:'4px 8px', fontSize:10.5}}>Válasz</button>
            </div>
          </div>
        );
      })}
    </div>
  );
}

// =================== TIMELINE ===================
function Timeline({ events }) {
  const list = events || [];
  return (
    <div style={{position:'relative', paddingLeft:8}}>
      <div style={{position:'absolute', left:14, top:8, bottom:8, width:2, background:C.lineSoft}}/>
      {list.map((e,i) => (
        <div key={i} style={{display:'flex', alignItems:'flex-start', gap:14, marginBottom:14, position:'relative'}}>
          <div style={{width:14, height:14, borderRadius:'50%', background: e.gold?C.gold : e.ok?C.ok:C.navy, border:`2px solid ${C.white}`, flexShrink:0, marginTop:2, marginLeft:1, boxShadow:`0 0 0 1px ${e.gold?C.gold:e.ok?C.ok:C.navy}`}}/>
          <div style={{width:54, flexShrink:0, fontFamily:'"DM Mono", monospace', fontSize:10.5, color:C.mute, paddingTop:2}}>
            <div style={{color:C.navyDeep, fontWeight:600}}>{e.t}</div>
            <div>{e.date.slice(5)}</div>
          </div>
          <div style={{flex:1, paddingBottom:4}}>
            <div style={{fontSize:12, fontWeight:600, color: e.gold?C.goldDeep:C.navyDeep}}>{e.label}</div>
            <div style={{fontSize:11, color:C.mute, marginTop:2, lineHeight:1.5}}>{e.desc}</div>
          </div>
        </div>
      ))}
    </div>
  );
}

// =================== VERIFICATION TABLE ===================
function VerificationTable({ items, onDetail }) {
  const list = items || [];
  return (
    <div style={{margin:'-16px'}}>
      <div style={{display:'grid', gridTemplateColumns:'1.4fr 0.85fr 1fr 0.55fr auto 0.85fr 0.4fr', gap:8, padding:'10px 16px', fontSize:10, fontFamily:'"DM Mono", monospace', color:C.mute, letterSpacing:0.8, background:C.creamSoft, borderBottom:`1px solid ${C.line}`}}>
        <div>ÜGYFÉL</div><div>KIKÜLDÉS</div><div>ÜGYNÖK</div><div>★</div><div>E·M·K·P</div><div>ÁLLAPOT</div><div></div>
      </div>
      {list.length === 0 && (
        <div style={{padding:'28px 16px', textAlign:'center', color:C.mute, fontSize:13}}>Nincs megjeleníthető kérés.</div>
      )}
      {list.map((v,i) => {
        const stateMap = {
          published:  { label:'PUBLIKÁLVA', bg:'#E8F1E5', fg:C.ok },
          waiting:    { label:'VÁRAKOZIK',  bg:'#FAF1DF', fg:C.goldDeep },
          internal:   { label:'BELSŐ ÚTON', bg:C.creamSoft, fg:C.navy },
          sent:       { label:'KIKÜLDVE',   bg:'#EEF2FF', fg:'#4455AA' },
          opened:     { label:'MEGNYITVA',  bg:'#FFF8E6', fg:C.warn },
          disappeared:{ label:'ELTŰNT',     bg:'#F6E5E3', fg:C.bad },
          failed:     { label:'SIKERTELEN', bg:'#F6E5E3', fg:C.bad },
        };
        const st = stateMap[v.state] || { label:(v.state||'–').toUpperCase(), bg:C.creamSoft, fg:C.mute };

        const client    = v.contact_name  || v.client  || '–';
        const agentName = v.agent_name    || v.agent   || '–';
        const sentStr   = v.sent_at       ? v.sent_at.slice(0,10)       : (v.sent  || '–');
        const pubStr    = v.published_at  ? v.published_at.slice(0,10)  : (v.published || '—');
        const star      = v.star_rating   ?? v.star    ?? 0;
        const delta     = v.wait_duration || v.delta   || '–';
        const hasAlert  = v.sla_status === 'breach' || v.sla_status === 'sla-due';

        // tracking pills
        const steps = [
          { key:'sent_at',      label:'E', title:'Elküldve',   val:v.sent_at      },
          { key:'opened_at',    label:'M', title:'Megnyitva',  val:v.opened_at    },
          { key:'clicked_at',   label:'K', title:'Kattintott', val:v.clicked_at   },
          { key:'published_at', label:'P', title:'Publikált',  val:v.published_at },
        ];

        return (
          <div key={i} style={{display:'grid', gridTemplateColumns:'1.4fr 0.85fr 1fr 0.55fr auto 0.85fr 0.4fr', gap:8, padding:'10px 16px', fontSize:12, alignItems:'center', borderBottom: i<list.length-1?`1px solid ${C.lineSoft}`:'none', cursor:'pointer'}} onClick={() => onDetail && onDetail(v)}>
            {/* Ügyfél */}
            <div style={{display:'flex', alignItems:'center', gap:6, minWidth:0}}>
              {hasAlert && <span style={{width:5, height:5, borderRadius:'50%', background:C.bad, flexShrink:0}}/>}
              <span style={{fontWeight:600, color:C.navyDeep, overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap'}}>{client}</span>
            </div>
            {/* Kiküldés */}
            <div style={{fontFamily:'"DM Mono", monospace', color:C.mute, fontSize:10.5}}>{sentStr}</div>
            {/* Ügynök */}
            <div style={{fontSize:11.5, color:C.navy, overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap'}}>{agentName}</div>
            {/* Csillag */}
            <div>{star > 0 ? <Stars value={star} size={10}/> : <span style={{color:C.mute, fontSize:11}}>—</span>}</div>
            {/* Tracking pills */}
            <div style={{display:'flex', gap:3}}>
              {steps.map(s => (
                <span key={s.key} title={s.title + (s.val ? ': ' + s.val.slice(0,16) : ': nem')} style={{
                  width:18, height:18, borderRadius:4, fontSize:9, fontWeight:700,
                  display:'flex', alignItems:'center', justifyContent:'center',
                  fontFamily:'"DM Mono", monospace',
                  background: s.val ? C.navy : C.lineSoft,
                  color: s.val ? C.cream : C.mute,
                }}>{s.label}</span>
              ))}
            </div>
            {/* Állapot */}
            <div>
              <span style={{fontSize:9.5, padding:'2px 6px', borderRadius:4, background:st.bg, color:st.fg, fontFamily:'"DM Mono", monospace', fontWeight:700, whiteSpace:'nowrap'}}>{st.label}</span>
            </div>
            {/* Részletek */}
            <div style={{textAlign:'right'}}>
              <button onClick={e => { e.stopPropagation(); onDetail && onDetail(v); }} style={{background:'transparent', border:'none', color:C.mute, cursor:'pointer'}}><Ico.arrow/></button>
            </div>
          </div>
        );
      })}
    </div>
  );
}
