// =================== app-views.jsx ===================
// Part 2 of Fodor Review OS React frontend.
// Runs AFTER app-core.jsx — all core components (C, Ico, Stars, Sidebar, Card,
// Stat, Spark, Tabs, Toggle, LoadingState, ErrorBanner, NewRequestModal,
// pillBtn, iconBtn, FunnelChart, ChannelBreakdown, AutomationList, ReviewStream,
// ProfileTable, AgentDetail, AgentGoals, AutomationBuilder, RecipeList,
// AutomationFullList, FlaggedList, Timeline, VerificationTable, OfficeCompare,
// KeywordCloud, CompetitorBar, StackedBars, Heatmap, Legend, Field, statusChip,
// apiFetch) are ALREADY AVAILABLE globally. DO NOT redefine them.

// =================== TOPBAR OVERRIDE ===================
// Overrides the TopBar defined in app-core.jsx to wire onNewRequest prop.
function TopBar({ title, subtitle, crumbs, onNewRequest, onFilter, onBell, onMenuOpen, isMobile }) {
  return (
    <div style={{
      padding: isMobile ? '12px 16px' : '18px 28px 16px',
      background:C.creamSoft,
      borderBottom:`1px solid ${C.line}`,
      display:'flex', alignItems:'center', justifyContent:'space-between',
      gap:10, flexShrink:0
    }}>
      <div style={{display:'flex', alignItems:'center', gap:10, minWidth:0}}>
        {isMobile && (
          <button onClick={onMenuOpen} style={{
            width:34, height:34, borderRadius:6, border:`1px solid ${C.line}`,
            background:C.white, cursor:'pointer', display:'flex', flexDirection:'column',
            alignItems:'center', justifyContent:'center', gap:4, flexShrink:0, padding:8
          }}>
            {[0,1,2].map(i => (
              <span key={i} style={{display:'block', width:14, height:1.5, background:C.navy, borderRadius:1}}/>
            ))}
          </button>
        )}
        <div style={{minWidth:0}}>
          {!isMobile && (
            <div style={{fontSize:10.5, fontFamily:'"DM Mono", monospace', color:C.mute, letterSpacing:1.3, marginBottom:4}}>
              {(crumbs||[]).map((cr,i)=>(<span key={i}>{i>0 && ' · '}{cr}</span>))}
            </div>
          )}
          <h1 style={{fontSize: isMobile ? 17 : 24, fontFamily:'"DM Serif Display", serif', color:C.navyDeep, margin:0, letterSpacing:-0.3, whiteSpace:'nowrap', overflow:'hidden', textOverflow:'ellipsis'}}>{title}</h1>
          {subtitle && !isMobile && <div style={{fontSize:12.5, color:C.mute, marginTop:2}}>{subtitle}</div>}
        </div>
      </div>
      <div style={{display:'flex', alignItems:'center', gap:isMobile ? 6 : 10, flexShrink:0}}>
        {!isMobile && (
          <div style={{display:'flex', gap:6, alignItems:'center', background:C.white, border:`1px solid ${C.line}`, borderRadius:6, padding:'6px 10px', fontSize:11.5, color:C.navy}}>
            <Ico.dot style={{color:C.ok}}/> Élő · API
          </div>
        )}
        {!isMobile && <button style={pillBtn(false)} onClick={onFilter}><Ico.filter/> Szűrő</button>}
        <button style={{...pillBtn(true), padding: isMobile ? '7px 10px' : '7px 12px'}} onClick={onNewRequest}>
          <Ico.plus/>{!isMobile && ' Új kérés'}
        </button>
        <button style={iconBtn()} onClick={onBell}><Ico.bell/><span style={{position:'absolute', top:6, right:6, width:6, height:6, borderRadius:'50%', background:C.gold}}/></button>
      </div>
    </div>
  );
}

// =================== DASHBOARD ===================
function Dashboard({ onNavigate }) {
  const w = useWindowWidth();
  const isMobile = w < 768;
  const isTablet = w < 1024;
  const [stats, setStats] = useState(null);
  const [recentReviews, setRecentReviews] = useState(null);
  const [automations, setAutomations] = useState(null);
  const [agents, setAgents] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    Promise.all([
      apiFetch('api/stats.php'),
      apiFetch('api/reviews.php?action=recent&limit=5'),
      apiFetch('api/automations.php'),
      apiFetch('api/agents.php'),
    ]).then(([s, rv, aut, ag]) => {
      setStats(s);
      setRecentReviews(rv.reviews || rv.data || []);
      setAutomations(aut.automations || aut.data || []);
      setAgents(ag.data || ag);
    }).catch(() => setError('API nem elérhető — demo adatok'));
  }, []);

  const handleToggle = (id) => {
    apiFetch(`api/automations.php?action=toggle&id=${id}`, { method: 'POST' })
      .then(() => setAutomations(prev => (prev || []).map(a => a.id == id ? { ...a, active: !a.active } : a)));
  };

  const kpi = stats?.kpi || {};

  return (
    <div style={{padding: isMobile ? 14 : 24, display:'flex', flexDirection:'column', gap: isMobile ? 12 : 18}}>
      {error && <ErrorBanner msg={error}/>}

      {/* 4 stat cards */}
      {(() => {
        const trend14 = (stats?.recent_trend || []).map(x => x.count || 0);
        const funnel  = (stats?.funnel_30d  || []).map(x => x.requests || 0);
        return (
        <div style={{display:'grid', gridTemplateColumns: isMobile ? '1fr 1fr' : 'repeat(4, 1fr)', gap: isMobile ? 10 : 14}}>
          <Stat
            label="Új értékelések · hó"
            value={kpi.new_reviews_month ?? '—'}
            accent={C.gold}
            spark={<Spark data={trend14.length ? trend14 : [0]} color={C.gold}/>}
          />
          <Stat
            label="Átlagos csillag"
            value={kpi.avg_star ? kpi.avg_star.toFixed(2) : '—'}
            unit="/ 5.0"
            accent={C.navy}
            spark={<Spark data={[kpi.avg_star || 0]} color={C.navy}/>}
          />
          <Stat
            label="Konverzió"
            value={kpi.conversion_rate ?? '—'}
            unit={kpi.conversion_rate != null ? '%' : ''}
            accent={C.gold}
            spark={<Spark data={funnel.length ? funnel : [0]} color={C.goldDeep}/>}
          />
          <Stat
            label="Elküldött kérések"
            value={kpi.total_sent ?? 0}
            accent={C.navy}
            spark={<Spark data={funnel.length ? funnel : [0]} color={C.navy}/>}
          />
        </div>
        );
      })()}

      {/* Middle row — charts */}
      <div style={{display:'grid', gridTemplateColumns: isMobile ? '1fr' : '2fr 1fr', gap: isMobile ? 12 : 14}}>
        <Card
          title="Értékelés-folyamat — utolsó 30 nap"
          subtitle="Kérés → Megnyitás → Csillag → Publikáció"
          action={<Tabs items={['30N','3H','12H','ÉV']}/>}
        >
          <FunnelChart data={stats?.funnel_30d}/>
        </Card>
        <Card title="Csatorna szerinti megoszlás" subtitle="Honnan érkezik a kérés">
          <ChannelBreakdown data={stats?.channel_breakdown}/>
        </Card>
      </div>

      {/* Bottom row */}
      <div style={{display:'grid', gridTemplateColumns: isMobile ? '1fr' : '1.4fr 1fr', gap: isMobile ? 12 : 14}}>
        <Card
          title="Aktív automatizmusok"
          subtitle="Futás és teljesítmény"
          action={<button onClick={() => onNavigate && onNavigate('automations')} style={{...pillBtn(false), padding:'5px 10px', fontSize:11}}><Ico.plus/> Hozzáadás</button>}
        >
          <AutomationList automations={automations} onToggle={handleToggle}/>
        </Card>
        <Card
          title="Legutóbbi értékelések"
          subtitle="Élő stream a Google-ről"
          action={<button onClick={() => onNavigate && onNavigate('inbox')} style={{...pillBtn(false), padding:'5px 10px', fontSize:11}}>Összes</button>}
        >
          <ReviewStream reviews={recentReviews}/>
        </Card>
      </div>

      <Card
        title="Iroda / ügynök teljesítmény"
        subtitle="Az utolsó 30 nap összevont mutatói"
        action={<Tabs items={['Iroda','Ügynök','Lokáció']} active={1}/>}
      >
        <ProfileTable agents={stats?.agent_leaderboard || agents}/>
      </Card>
    </div>
  );
}

// =================== PROFILES VIEW ===================
function ProfilesView({ onNavigate }) {
  const w = useWindowWidth();
  const isMobile = w < 768;
  const isTablet = w < 1024;
  const [offices, setOffices] = useState(null);
  const [agents, setAgents] = useState(null);
  const [selectedAgent, setSelectedAgent] = useState(null);
  const [officeModal, setOfficeModal] = useState(null);
  const [officeErr, setOfficeErr] = useState('');
  const [agentModal, setAgentModal] = useState(null); // null | agent object (id present = edit, no id = create)
  const [agentErr, setAgentErr] = useState('');

  const loadAll = () => Promise.all([apiFetch('api/offices.php'), apiFetch('api/agents.php')])
    .then(([o, a]) => { setOffices(o.data || o); setAgents(a.data || a); })
    .catch(() => {});

  const loadOffices = () => apiFetch('api/offices.php').then(o => setOffices(o.data || o)).catch(() => {});

  useEffect(() => { loadAll(); }, []);

  const saveAgent = () => {
    if (!agentModal?.name) { setAgentErr('Név kötelező'); return; }
    if (!agentModal?.office_id) { setAgentErr('Iroda kötelező'); return; }
    if (!agentModal?.email) { setAgentErr('Email kötelező'); return; }
    const isEdit = !!agentModal.id;
    const url = isEdit ? `api/agents.php?id=${agentModal.id}` : 'api/agents.php';
    const method = isEdit ? 'PUT' : 'POST';
    apiFetch(url, { method, body: JSON.stringify(agentModal) })
      .then(r => {
        if (r.error) throw new Error(r.error);
        setAgentModal(null);
        setAgentErr('');
        loadAll().then(() => {
          if (!isEdit) setSelectedAgent(r);
        });
      })
      .catch(e => setAgentErr(e.message));
  };

  const deactivateAgent = (ag) => {
    if (!confirm(`Biztosan deaktiválod: ${ag.name}?`)) return;
    apiFetch(`api/agents.php?id=${ag.id}`, { method: 'DELETE' })
      .then(() => loadAll())
      .catch(e => alert(e.message));
  };

  const saveOffice = () => {
    if (!officeModal?.name) { setOfficeErr('Iroda neve kötelező'); return; }
    const method = officeModal.id ? 'PUT' : 'POST';
    const url = officeModal.id ? `api/offices.php?id=${officeModal.id}` : 'api/offices.php';
    apiFetch(url, { method, body: JSON.stringify(officeModal) })
      .then(r => {
        if (r.error) throw new Error(r.error);
        setOfficeModal(null);
        setOfficeErr('');
        loadOffices();
      })
      .catch(e => setOfficeErr(e.message));
  };

  const officeList = offices || [];

  // Normalise API fields vs design mock fields
  const normaliseOffice = (o) => ({
    name: o.name,
    addr: o.addr || o.address,
    agents: o.agents || o.agent_count,
    reviews: o.reviews || o.review_count,
    avg: o.avg || o.avg_rating,
    googleVerified: o.googleVerified || !!o.google_verified,
    mainAgent: o.mainAgent || o.main_agent_name,
  });

  return (
    <div style={{padding: isMobile ? 14 : 24, display:'flex', flexDirection:'column', gap: isMobile ? 12 : 18}}>
      {/* Office cards */}
      <div>
        <div style={{display:'flex', alignItems:'center', justifyContent:'space-between', marginBottom:10, flexWrap:'wrap', gap:8}}>
          <div>
            <h3 style={{margin:0, fontFamily:'"DM Serif Display", serif', fontSize:15, color:C.navyDeep}}>Iroda profilok</h3>
            <div style={{fontSize:11.5, color:C.mute}}>Google Business Profile-ok és belső irodai egységek</div>
          </div>
          <button onClick={() => setOfficeModal({name:'', address:'', google_place_id:''})} style={pillBtn(true)}><Ico.plus/> Új iroda</button>
        </div>
        {officeList.length === 0 && offices !== null && <div style={{padding:24, color:C.mute, fontSize:13, textAlign:'center'}}>Még nincs iroda rögzítve. Kattints az „Új iroda" gombra.</div>}
        <div style={{display:'grid', gridTemplateColumns: isMobile ? '1fr' : isTablet ? '1fr 1fr' : 'repeat(4, 1fr)', gap:12}}>
          {officeList.map((raw, i) => {
            const o = normaliseOffice(raw);
            return (
              <div key={i} style={{background:C.white, border:`1px solid ${C.line}`, borderRadius:10, padding:14, position:'relative'}}>
                <div style={{display:'flex', alignItems:'flex-start', justifyContent:'space-between', marginBottom:10}}>
                  <div style={{width:36, height:36, borderRadius:8, background:C.navy, display:'flex', alignItems:'center', justifyContent:'center'}}>
                    <Ico.google/>
                  </div>
                  {o.googleVerified
                    ? <span style={{fontSize:9.5, padding:'2px 6px', borderRadius:4, background:'#E8F1E5', color:C.ok, fontFamily:'"DM Mono", monospace', fontWeight:700}}><Ico.check width={9} height={9}/> AKTÍV</span>
                    : null
                  }
                </div>
                <div style={{fontSize:13, fontWeight:600, color:C.navyDeep, marginBottom:2}}>{o.name}</div>
                <div style={{fontSize:11, color:C.mute, marginBottom:12}}>{o.addr}</div>
                <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap:8, paddingTop:10, borderTop:`1px solid ${C.lineSoft}`}}>
                  <div>
                    <div style={{fontSize:9.5, fontFamily:'"DM Mono", monospace', color:C.mute, letterSpacing:0.6}}>ÁTLAG</div>
                    <div style={{display:'flex', alignItems:'center', gap:4, marginTop:2}}>
                      <span style={{fontFamily:'"DM Serif Display", serif', fontSize:16, color:C.navyDeep}}>{o.avg}</span>
                      <Ico.star style={{color:C.gold}} width={10} height={10}/>
                    </div>
                  </div>
                  <div>
                    <div style={{fontSize:9.5, fontFamily:'"DM Mono", monospace', color:C.mute, letterSpacing:0.6}}>ÉRTÉKELÉS</div>
                    <div style={{fontFamily:'"DM Serif Display", serif', fontSize:16, color:C.navyDeep, marginTop:2}}>{o.reviews}</div>
                  </div>
                  <div>
                    <div style={{fontSize:9.5, fontFamily:'"DM Mono", monospace', color:C.mute, letterSpacing:0.6}}>ÜGYNÖK</div>
                    <div style={{fontFamily:'"DM Serif Display", serif', fontSize:16, color:C.navyDeep, marginTop:2}}>{o.agents}</div>
                  </div>
                  <div>
                    <div style={{fontSize:9.5, fontFamily:'"DM Mono", monospace', color:C.mute, letterSpacing:0.6}}>FELELŐS</div>
                    <div style={{fontSize:11.5, color:C.navyDeep, marginTop:4, fontWeight:600}}>{o.mainAgent}</div>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      </div>

      <Card
        title={`Összes ügynök · ${agents ? agents.length + ' fő' : '…'}`}
        subtitle="Kattints egy sorra a profil megnyitásához"
        action={<button onClick={() => setAgentModal({name:'',office_id:(offices?.[0]?.id||''),role:'',phone:'',email:'',review_link:'',personalized_msg:'',status:'active'})} style={{...pillBtn(true),padding:'5px 10px',fontSize:11}}><Ico.plus/> Új ügynök</button>}
      >
        <ProfileTable
          agents={agents}
          selectedAgent={selectedAgent || agents?.[0]}
          onSelect={a => setSelectedAgent(a)}
        />
      </Card>

      {/* Selected agent detail */}
      {(() => { const ag = selectedAgent || agents?.[0]; const agName = ag ? (ag.name || ag.agent_name || '') : ''; return (
      <div style={{display:'grid', gridTemplateColumns: isMobile ? '1fr' : '1fr 1.4fr', gap:14}}>
        <Card
          title={agName ? 'Ügynök profil · ' + agName : 'Ügynök profil'}
          subtitle="Részletes beállítások"
          action={ag ? (
            <div style={{display:'flex', gap:6}}>
              <button onClick={() => setAgentModal({...ag})} style={{...pillBtn(false), padding:'5px 10px', fontSize:11}}>Szerkeszt</button>
              {ag.status === 'active' && <button onClick={() => deactivateAgent(ag)} style={{...pillBtn(false), padding:'5px 10px', fontSize:11, color:C.bad, borderColor:C.bad}}>Deaktivál</button>}
            </div>
          ) : null}
        >
          <AgentDetail agent={ag}/>
        </Card>
        <Card title="Egyéni cél és teljesítmény" subtitle="Negyedéves követés">
          <AgentGoals agent={ag}/>
        </Card>
      </div>
      ); })()}

      {/* Iroda modal */}
      {officeModal && (
        <div style={{position:'fixed', inset:0, background:'rgba(15,26,38,0.6)', zIndex:1000, display:'flex', alignItems:'center', justifyContent:'center'}}>
          <div style={{background:C.white, borderRadius:12, padding:28, width:480, maxWidth:'95vw', border:`1px solid ${C.line}`}}>
            <div style={{fontFamily:'"DM Serif Display", serif', fontSize:18, color:C.navyDeep, marginBottom:20}}>
              {officeModal.id ? 'Iroda szerkesztése' : 'Új iroda'}
            </div>
            {officeErr && <div style={{background:'#F6E5E3', color:C.bad, borderRadius:6, padding:'8px 12px', fontSize:12, marginBottom:14}}>{officeErr}</div>}
            {[['Iroda neve *', 'name'], ['Cím', 'address'], ['Google Place ID', 'google_place_id']].map(([lbl, key]) => (
              <div key={key} style={{marginBottom:12}}>
                <div style={{fontSize:11, color:C.mute, marginBottom:4}}>{lbl}</div>
                <input
                  type="text"
                  value={officeModal[key] || ''}
                  onChange={e => setOfficeModal(m => ({...m, [key]: e.target.value}))}
                  style={{width:'100%', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}
                />
              </div>
            ))}
            <div style={{display:'flex', gap:8, justifyContent:'flex-end', marginTop:8}}>
              <button onClick={() => { setOfficeModal(null); setOfficeErr(''); }} style={{...pillBtn(false), padding:'8px 16px'}}>Mégse</button>
              <button onClick={saveOffice} style={{...pillBtn(true), padding:'8px 16px'}}>Mentés</button>
            </div>
          </div>
        </div>
      )}

      {/* Ügynök create/edit modal */}
      {agentModal && (
        <div style={{position:'fixed', inset:0, background:'rgba(15,26,38,0.6)', zIndex:1000, display:'flex', alignItems:'center', justifyContent:'center', padding:'16px'}}>
          <div style={{background:C.white, borderRadius:12, padding:28, width:520, maxWidth:'100%', maxHeight:'90vh', overflowY:'auto', border:`1px solid ${C.line}`}}>
            <div style={{fontFamily:'"DM Serif Display", serif', fontSize:18, color:C.navyDeep, marginBottom:20}}>
              {agentModal.id ? 'Ügynök szerkesztése' : 'Új ügynök'}
            </div>
            {agentErr && <div style={{background:'#F6E5E3', color:C.bad, borderRadius:6, padding:'8px 12px', fontSize:12, marginBottom:14}}>{agentErr}</div>}

            {/* Name + Role */}
            <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap:12, marginBottom:12}}>
              {[['Teljes név *', 'name', 'text'], ['Szerepkör', 'role', 'text']].map(([lbl, key, type]) => (
                <div key={key}>
                  <div style={{fontSize:11, color:C.mute, marginBottom:4}}>{lbl}</div>
                  <input type={type} value={agentModal[key] || ''} onChange={e => setAgentModal(m => ({...m, [key]: e.target.value}))}
                    style={{width:'100%', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}/>
                </div>
              ))}
            </div>

            {/* Email + Phone */}
            <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap:12, marginBottom:12}}>
              {[['Email *', 'email', 'email'], ['Telefon', 'phone', 'tel']].map(([lbl, key, type]) => (
                <div key={key}>
                  <div style={{fontSize:11, color:C.mute, marginBottom:4}}>{lbl}</div>
                  <input type={type} value={agentModal[key] || ''} onChange={e => setAgentModal(m => ({...m, [key]: e.target.value}))}
                    style={{width:'100%', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}/>
                </div>
              ))}
            </div>

            {/* Office + Status */}
            <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap:12, marginBottom:12}}>
              <div>
                <div style={{fontSize:11, color:C.mute, marginBottom:4}}>Iroda *</div>
                <select value={agentModal.office_id || ''} onChange={e => setAgentModal(m => ({...m, office_id: e.target.value}))}
                  style={{width:'100%', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}>
                  <option value="">— válasszon —</option>
                  {(offices || []).map(o => <option key={o.id} value={o.id}>{o.name}</option>)}
                </select>
              </div>
              <div>
                <div style={{fontSize:11, color:C.mute, marginBottom:4}}>Státusz</div>
                <select value={agentModal.status || 'active'} onChange={e => setAgentModal(m => ({...m, status: e.target.value}))}
                  style={{width:'100%', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}>
                  <option value="active">Aktív</option>
                  <option value="inactive">Inaktív</option>
                </select>
              </div>
            </div>

            {/* Review link */}
            <div style={{marginBottom:12}}>
              <div style={{fontSize:11, color:C.mute, marginBottom:4}}>Google értékelés-link</div>
              <input type="url" value={agentModal.review_link || ''} onChange={e => setAgentModal(m => ({...m, review_link: e.target.value}))}
                placeholder="https://g.page/r/..."
                style={{width:'100%', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}/>
            </div>

            {/* Personalized message */}
            <div style={{marginBottom:20}}>
              <div style={{fontSize:11, color:C.mute, marginBottom:4}}>Személyesített üzenet (sablonban: {'{'}{'}'})</div>
              <textarea value={agentModal.personalized_msg || ''} onChange={e => setAgentModal(m => ({...m, personalized_msg: e.target.value}))}
                rows={3} placeholder="Pl. Örömmel segítettem Önnek az ügyletben..."
                style={{width:'100%', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit', resize:'vertical'}}/>
            </div>

            <div style={{display:'flex', gap:8, justifyContent:'flex-end'}}>
              <button onClick={() => { setAgentModal(null); setAgentErr(''); }} style={{...pillBtn(false), padding:'8px 16px'}}>Mégse</button>
              <button onClick={saveAgent} style={{...pillBtn(true), padding:'8px 16px'}}>Mentés</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// =================== AUTOMATIONS VIEW ===================
const TRIGGER_TYPES = [
  {val:'adásvétel',      label:'Adásvétel'},
  {val:'bérleti_aláírás',label:'Bérleti aláírás'},
  {val:'megtekintés',    label:'Megtekintés'},
  {val:'ünnep',          label:'Ünnepi'},
  {val:'inaktív',        label:'Inaktív ügyfél'},
  {val:'egyéb',          label:'Egyéb'},
];

function AutomationsView() {
  const w = useWindowWidth();
  const isMobile = w < 768;
  const isTablet = w < 1024;
  const [automations, setAutomations]             = useState(null);
  const [templates, setTemplates]                 = useState([]);
  const [tabIdx, setTabIdx]                       = useState(0);
  const [editModal, setEditModal]                 = useState(null);
  const [editErr, setEditErr]                     = useState('');
  const [selectedAutoForBuilder, setSelectedAutoForBuilder] = useState(null);

  const load = () => apiFetch('api/automations.php')
    .then(d => {
      const list = d.automations || d.data || [];
      setAutomations(list);
      setSelectedAutoForBuilder(prev => prev ?? list[0] ?? null);
    })
    .catch(() => {});

  useEffect(() => {
    load();
    apiFetch('api/templates.php').then(d => setTemplates(d.data || [])).catch(() => {});
  }, []);

  const filteredAutomations = useMemo(() => {
    if (!automations) return automations;
    if (tabIdx === 1) return automations.filter(a => a.active === 1 || a.active === true);
    if (tabIdx === 2) return automations.filter(a => !a.active && a.active !== 1);
    if (tabIdx === 3) return automations.filter(a =>
      ['ünnep','inaktív'].includes(a.trigger_type)
    );
    return automations;
  }, [automations, tabIdx]);

  const handleToggle = (id) => {
    apiFetch(`api/automations.php?action=toggle&id=${id}`, { method: 'POST' })
      .then(() => setAutomations(prev => (prev || []).map(a => a.id == id ? { ...a, active: a.active ? 0 : 1 } : a)));
  };

  const handleGear = (a) => {
    let cfg = {};
    try { cfg = JSON.parse(a.trigger_config || '{}'); } catch(e) {}
    setEditModal({
      id: a.id, name: a.name, trigger_type: a.trigger_type || 'adásvétel',
      channel: a.channel || 'email',
      delay_hours: a.delay_hours ?? 0,
      delay_unit: (a.delay_hours || 0) % 24 === 0 && (a.delay_hours || 0) > 0 ? 'days' : 'hours',
      template_id: a.template_id || '',
      reminder_enabled: !!cfg.reminder_enabled,
      reminder_days: cfg.reminder_days || 3,
      reminder_template_id: cfg.reminder_template_id || '',
      no_click_action: cfg.no_click_action || 'remind',
      custom_note: cfg.custom_note || '',
    });
  };

  const handleNewAuto = () => setEditModal({
    id: null, name: '', trigger_type: 'adásvétel', channel: 'email',
    delay_hours: 0, delay_unit: 'hours', template_id: '',
    reminder_enabled: false, reminder_days: 3, reminder_template_id: '',
    no_click_action: 'remind', custom_note: '',
  });

  const saveAuto = () => {
    const m = editModal;
    if (!m.name) { setEditErr('Név kötelező'); return; }
    if (!m.template_id) { setEditErr('Sablon kötelező'); return; }
    const delayH = m.delay_unit === 'days'
      ? parseInt(m.delay_hours) * 24
      : parseInt(m.delay_hours) || 0;
    const triggerCfg = {
      reminder_enabled: m.reminder_enabled,
      reminder_days: parseInt(m.reminder_days) || 3,
      reminder_template_id: m.reminder_template_id ? parseInt(m.reminder_template_id) : null,
      no_click_action: m.no_click_action,
      custom_note: m.custom_note || '',
    };
    const method = m.id ? 'PUT' : 'POST';
    const url    = m.id ? `api/automations.php?id=${m.id}` : 'api/automations.php';
    apiFetch(url, {
      method,
      body: JSON.stringify({
        name: m.name, trigger_type: m.trigger_type, channel: m.channel,
        delay_hours: delayH,
        template_id: parseInt(m.template_id),
        trigger_config: triggerCfg,
      })
    })
    .then(r => {
      if (r.error) throw new Error(r.error);
      setEditModal(null); setEditErr(''); load();
    })
    .catch(e => setEditErr(e.message));
  };

  const handleRecipe = (recipe) => {
    const tpl = templates.find(t => t.name && t.name.toLowerCase().includes(recipe.key)) || templates[0];
    if (!tpl) { alert('Nincs elérhető sablon — hozz létre egyet az Üzenetsablonok menüben'); return; }
    setEditModal({
      id: null, name: recipe.name, trigger_type: recipe.trigger, channel: recipe.channel,
      delay_hours: recipe.delay || 0, template_id: tpl.id,
    });
  };

  const activeCount    = automations ? automations.filter(a => a.active).length : 0;
  const totalCount     = automations ? automations.length : 0;
  const totalRuns      = automations ? automations.reduce((s, a) => s + (a.runs || 0), 0) : 0;
  const totalConvCount = automations ? automations.reduce((s, a) => s + (a.conv_count || 0), 0) : 0;
  const avgConvStr     = totalRuns > 0
    ? Math.round((totalConvCount / totalRuns) * 100) + '%'
    : '—';

  return (
    <div style={{padding: isMobile ? 14 : 24, display:'flex', flexDirection:'column', gap: isMobile ? 12 : 18}}>
      <div style={{display:'grid', gridTemplateColumns: isMobile ? '1fr 1fr' : 'repeat(4, 1fr)', gap: isMobile ? 10 : 14}}>
        <Stat label="Aktív szabály" value={activeCount.toString()} delta={totalCount - activeCount + ' inaktív'} deltaPos={activeCount > 0} accent={C.gold} spark={<Spark data={[0,1,2,2,3,3,activeCount||1]} color={C.gold}/>}/>
        <Stat label="Összes futás" value={totalRuns.toString()} delta="összesen" deltaPos accent={C.navy} spark={<Spark data={[0,1,2,3,Math.max(totalRuns,1)]} color={C.navy}/>}/>
        <Stat label="Konverziók" value={totalConvCount.toString()} delta="összesen" deltaPos accent={C.ok} spark={<Spark data={[0,1,1,2,Math.max(totalConvCount,1)]} color={C.ok}/>}/>
        <Stat label="Átlag konv." value={avgConvStr} delta="konverzió" deltaPos accent={C.goldDeep} spark={<Spark data={[10,15,20,25,30,35,40]} color={C.goldDeep}/>}/>
      </div>

      <Card
        title={selectedAutoForBuilder ? `Folyamatábra · ${selectedAutoForBuilder.name}` : 'Automatizmus-szerkesztő · áttekintés'}
        subtitle={selectedAutoForBuilder ? `${selectedAutoForBuilder.active ? 'Aktív' : 'Inaktív'} · Kattintson egy sorra a listában a váltáshoz` : 'Kattintson egy automatizmusra az áttekintéshez'}
        action={selectedAutoForBuilder
          ? <button onClick={() => handleGear(selectedAutoForBuilder)} style={{...pillBtn(true), padding:'5px 12px', fontSize:11, display:'flex', alignItems:'center', gap:5}}><Ico.gear width={12} height={12}/> Szerkesztés</button>
          : null
        }
      >
        <AutomationBuilder automation={selectedAutoForBuilder}/>
      </Card>

      <Card
        title={`Összes automatizmus`}
        subtitle={`${totalCount} darab · ${activeCount} aktív`}
        pad={false}
        action={
          <div style={{display:'flex', gap:8, alignItems:'center'}}>
            <Tabs items={['Mind','Aktív','Inaktív','Speciális']} active={tabIdx} onChange={setTabIdx}/>
            <button onClick={handleNewAuto} style={{...pillBtn(true), padding:'5px 10px', fontSize:11}}><Ico.plus/> Új</button>
          </div>
        }
      >
        <AutomationFullList automations={filteredAutomations} onToggle={handleToggle} onEdit={handleGear} onSelect={a => setSelectedAutoForBuilder(a)}/>
      </Card>

      {/* Edit / New modal — detailed */}
      {editModal && (
        <div style={{position:'fixed', inset:0, background:'rgba(15,26,38,0.6)', zIndex:1000, display:'flex', alignItems:'center', justifyContent:'center', padding:16}}>
          <div style={{background:C.white, borderRadius:12, width:580, maxWidth:'100%', border:`1px solid ${C.line}`, maxHeight:'92vh', overflowY:'auto', display:'flex', flexDirection:'column'}}>

            {/* Header */}
            <div style={{padding:'20px 24px 16px', borderBottom:`1px solid ${C.line}`, display:'flex', alignItems:'center', justifyContent:'space-between'}}>
              <div style={{fontFamily:'"DM Serif Display", serif', fontSize:18, color:C.navyDeep}}>
                {editModal.id ? 'Automatizmus szerkesztése' : 'Új automatizmus'}
              </div>
              <button onClick={() => { setEditModal(null); setEditErr(''); }} style={{background:'none', border:'none', cursor:'pointer', color:C.mute, fontSize:18, lineHeight:1}}>✕</button>
            </div>

            <div style={{padding:'20px 24px', display:'flex', flexDirection:'column', gap:20}}>
              {editErr && <div style={{background:'#F6E5E3', color:C.bad, borderRadius:6, padding:'8px 12px', fontSize:12}}>{editErr}</div>}

              {/* Section 1: Alapbeállítások */}
              <div>
                <div style={{fontSize:10, fontFamily:'"DM Mono", monospace', letterSpacing:1.2, color:C.mute, marginBottom:10}}>ALAPBEÁLLÍTÁSOK</div>
                <div style={{marginBottom:12}}>
                  <div style={{fontSize:11, color:C.mute, marginBottom:4}}>Automatizmus neve *</div>
                  <input value={editModal.name} onChange={e => setEditModal(m => ({...m, name: e.target.value}))}
                    placeholder="pl. Adásvétel utáni értékelés-kérő"
                    style={{width:'100%', boxSizing:'border-box', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}/>
                </div>
                <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap:12}}>
                  <div>
                    <div style={{fontSize:11, color:C.mute, marginBottom:4}}>Trigger — mikor induljon? *</div>
                    <select value={editModal.trigger_type} onChange={e => setEditModal(m => ({...m, trigger_type: e.target.value}))}
                      style={{width:'100%', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}>
                      {TRIGGER_TYPES.map(t => <option key={t.val} value={t.val}>{t.label}</option>)}
                    </select>
                  </div>
                  <div>
                    <div style={{fontSize:11, color:C.mute, marginBottom:4}}>Csatorna</div>
                    <select value={editModal.channel} onChange={e => setEditModal(m => ({...m, channel: e.target.value}))}
                      style={{width:'100%', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}>
                      <option value="email">Email</option>
                      <option value="sms">SMS</option>
                      <option value="mindkettő">Email + SMS</option>
                    </select>
                  </div>
                </div>
              </div>

              {/* Section 2: Kiküldés */}
              <div style={{paddingTop:16, borderTop:`1px solid ${C.lineSoft}`}}>
                <div style={{fontSize:10, fontFamily:'"DM Mono", monospace', letterSpacing:1.2, color:C.mute, marginBottom:10}}>KIKÜLDÉS</div>
                <div style={{marginBottom:12}}>
                  <div style={{fontSize:11, color:C.mute, marginBottom:4}}>Email sablon *</div>
                  <select value={editModal.template_id} onChange={e => setEditModal(m => ({...m, template_id: e.target.value}))}
                    style={{width:'100%', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}>
                    <option value="">— válasszon sablont —</option>
                    {templates.map(t => <option key={t.id} value={t.id}>{t.name} ({t.channel})</option>)}
                  </select>
                </div>
                <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap:12, marginBottom:12}}>
                  <div>
                    <div style={{fontSize:11, color:C.mute, marginBottom:4}}>Késleltetés — mennyit várjon?</div>
                    <div style={{display:'flex', gap:6}}>
                      <input type="number" min="0" value={editModal.delay_hours} onChange={e => setEditModal(m => ({...m, delay_hours: e.target.value}))}
                        style={{flex:1, padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}/>
                      <select value={editModal.delay_unit} onChange={e => setEditModal(m => ({...m, delay_unit: e.target.value}))}
                        style={{padding:'8px 10px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}>
                        <option value="hours">óra</option>
                        <option value="days">nap</option>
                      </select>
                    </div>
                    <div style={{fontSize:10.5, color:C.mute, marginTop:4}}>
                      {editModal.delay_hours == 0 ? 'Azonnali küldés' : `Kiküldés: ${editModal.delay_unit === 'days' ? editModal.delay_hours + ' nappal' : editModal.delay_hours + ' órával'} a trigger után`}
                    </div>
                  </div>
                  <div>
                    <div style={{fontSize:11, color:C.mute, marginBottom:4}}>Személyes megjegyzés (opcionális)</div>
                    <input value={editModal.custom_note} onChange={e => setEditModal(m => ({...m, custom_note: e.target.value}))}
                      placeholder="pl. Köszönjük a bizalmat!"
                      style={{width:'100%', boxSizing:'border-box', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}/>
                  </div>
                </div>
              </div>

              {/* Section 3: Elágazás — Ha nem kattintott */}
              <div style={{paddingTop:16, borderTop:`1px solid ${C.lineSoft}`}}>
                <div style={{fontSize:10, fontFamily:'"DM Mono", monospace', letterSpacing:1.2, color:C.mute, marginBottom:10}}>ELÁGAZÁS · HA NEM NYITOTTA MEG A LINKET</div>
                <label style={{display:'flex', alignItems:'center', gap:10, cursor:'pointer', marginBottom:12}}>
                  <input type="checkbox" checked={editModal.reminder_enabled}
                    onChange={e => setEditModal(m => ({...m, reminder_enabled: e.target.checked}))}
                    style={{width:16, height:16, accentColor:C.gold}}/>
                  <span style={{fontSize:13, color:C.navyDeep}}>Emlékeztető küldése, ha nem kattintott</span>
                </label>
                {editModal.reminder_enabled && (
                  <div style={{paddingLeft:26, display:'flex', flexDirection:'column', gap:10}}>
                    <div style={{display:'grid', gridTemplateColumns:'1fr 1fr', gap:12}}>
                      <div>
                        <div style={{fontSize:11, color:C.mute, marginBottom:4}}>Hány nappal később?</div>
                        <div style={{display:'flex', gap:6, alignItems:'center'}}>
                          <input type="number" min="1" max="30" value={editModal.reminder_days} onChange={e => setEditModal(m => ({...m, reminder_days: e.target.value}))}
                            style={{width:70, padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}/>
                          <span style={{fontSize:12, color:C.mute}}>nappal az első email után</span>
                        </div>
                      </div>
                      <div>
                        <div style={{fontSize:11, color:C.mute, marginBottom:4}}>Ha nem reagál az emlékeztetőre</div>
                        <select value={editModal.no_click_action} onChange={e => setEditModal(m => ({...m, no_click_action: e.target.value}))}
                          style={{width:'100%', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}>
                          <option value="remind">Lezárás (nem kattintott)</option>
                          <option value="flag">Megjelölés figyelésre</option>
                        </select>
                      </div>
                    </div>
                    <div>
                      <div style={{fontSize:11, color:C.mute, marginBottom:4}}>Emlékeztető sablon (opcionális)</div>
                      <select value={editModal.reminder_template_id} onChange={e => setEditModal(m => ({...m, reminder_template_id: e.target.value}))}
                        style={{width:'100%', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}>
                        <option value="">— ugyanaz mint az első email —</option>
                        {templates.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
                      </select>
                    </div>
                  </div>
                )}
                {!editModal.reminder_enabled && (
                  <div style={{fontSize:12, color:C.mute, paddingLeft:26}}>Ha nem kattint → automatikusan lezárva (nincs emlékeztető)</div>
                )}
              </div>
            </div>

            {/* Footer */}
            <div style={{padding:'16px 24px', borderTop:`1px solid ${C.line}`, display:'flex', gap:8, justifyContent:'flex-end'}}>
              <button onClick={() => { setEditModal(null); setEditErr(''); }} style={{...pillBtn(false), padding:'8px 18px'}}>Mégse</button>
              <button onClick={saveAuto} style={{...pillBtn(true), padding:'8px 18px'}}>Mentés</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// =================== VERIFY VIEW ===================
function VerifyView() {
  const w = useWindowWidth();
  const isMobile = w < 768;
  const isTablet = w < 1024;
  const [data, setData] = useState(null);
  const [agents, setAgents] = useState([]);
  const [replyModal, setReplyModal] = useState(null);
  const [replyState, setReplyState] = useState('waiting');
  const [assignModal, setAssignModal] = useState(null);
  const [assignAgentId, setAssignAgentId] = useState('');
  const [detailItem, setDetailItem] = useState(null);
  const [detailTimeline, setDetailTimeline] = useState(null);
  const [verifyTab, setVerifyTab] = useState(0);

  const reload = () => apiFetch('api/verify.php').then(setData).catch(() => {});

  useEffect(() => {
    reload();
    apiFetch('api/agents.php').then(d => setAgents(d.data || [])).catch(() => {});
  }, []);

  const handleReply = () => {
    if (!replyModal || !replyState) return;
    apiFetch(`api/inbox.php?id=${replyModal.id}&type=request`, {
      method: 'PUT',
      body: JSON.stringify({ state: replyState })
    }).then(() => { setReplyModal(null); setReplyState('waiting'); reload(); })
      .catch(e => alert('Hiba: ' + (e?.message || 'ismeretlen')));
  };

  const handleAssign = () => {
    if (!assignModal || !assignAgentId) return;
    apiFetch(`api/inbox.php?id=${assignModal.id}&type=request`, {
      method: 'PUT',
      body: JSON.stringify({ agent_id: parseInt(assignAgentId) })
    }).then(() => { setAssignModal(null); setAssignAgentId(''); reload(); })
      .catch(e => alert('Hiba: ' + (e?.message || 'ismeretlen')));
  };

  const handleDetail = (item) => {
    const rid = item.id;
    if (!rid) return;
    setDetailItem(item);
    setDetailTimeline(null);
    apiFetch(`api/verify.php?request_id=${rid}`)
      .then(d => setDetailTimeline(d.timeline || []))
      .catch(() => setDetailTimeline([]));
  };

  const kpi = data?.kpi || {};
  const slaStats = data?.sla_stats || {};

  const sentCount      = (data?.sla_monitor || []).length;
  const clickedCount   = (data?.waiting_publish || []).length;
  const notOpenedCount = (data?.not_opened || []).length;
  const publishedCount = (data?.sla_monitor || []).filter(x => x.state === 'published').length;

  const allItems = data?.sla_monitor || [];
  const tabItems = verifyTab === 0 ? allItems
    : verifyTab === 1 ? (data?.waiting_publish || [])
    : verifyTab === 2 ? (data?.not_opened || [])
    : allItems.filter(x => x.state === 'published');

  const handleMarkPublished = (item) => {
    apiFetch(`api/verify.php?action=mark_published&id=${item.id}`, { method: 'POST' })
      .then(() => reload())
      .catch(e => alert('Hiba: ' + (e?.message || 'ismeretlen')));
  };

  return (
    <div style={{padding: isMobile ? 14 : 24, display:'flex', flexDirection:'column', gap: isMobile ? 12 : 18}}>
      <div style={{display:'grid', gridTemplateColumns: isMobile ? '1fr 1fr' : 'repeat(4, 1fr)', gap: isMobile ? 10 : 14}}>
        <Stat
          label="Elküldött kérések"
          value={String(sentCount)}
          delta="összesen"
          deltaPos
          accent={C.navy}
          spark={<Spark data={[0,1,2,3,Math.max(sentCount,1)]} color={C.navy}/>}
        />
        <Stat
          label="Link megnyitva"
          value={String(clickedCount)}
          delta="várja megerősítést"
          deltaPos={clickedCount > 0}
          accent={C.gold}
          spark={<Spark data={[0,1,1,2,Math.max(clickedCount,1)]} color={C.gold}/>}
        />
        <Stat
          label="Nem nyitotta meg"
          value={String(notOpenedCount)}
          delta="5+ napja"
          deltaPos={false}
          accent={C.bad}
          spark={<Spark data={[0,1,0,1,Math.max(notOpenedCount,0)]} color={C.bad}/>}
        />
        <Stat
          label="Megjelent értékelés"
          value={String(publishedCount)}
          delta="megerősített"
          deltaPos={publishedCount > 0}
          accent={C.ok}
          spark={<Spark data={[0,1,2,3,Math.max(publishedCount,1)]} color={C.ok}/>}
        />
      </div>

      <div style={{display:'grid', gridTemplateColumns: isMobile ? '1fr' : '1fr 1.4fr', gap: isMobile ? 12 : 14}}>
        <Card title="Link megnyitva — megerősítés vár" subtitle="Ügyfél kattintott, de nem megerősített">
          {(data?.waiting_publish || []).length === 0
            ? <div style={{padding:20, color:C.mute, fontSize:13, textAlign:'center'}}>Nincs megerősítésre váró kattintás.</div>
            : (data.waiting_publish).map((item, i) => (
              <div key={i} style={{display:'flex', alignItems:'center', justifyContent:'space-between', padding:'10px 0', borderBottom:`1px solid ${C.lineSoft}`}}>
                <div>
                  <div style={{fontWeight:600, fontSize:13, color:C.navyDeep}}>{item.contact_name || '–'}</div>
                  <div style={{fontSize:11, color:C.mute}}>{item.agent_name} · kattintott: {item.wait_duration} ezelőtt</div>
                </div>
                <button onClick={() => handleMarkPublished(item)} style={{...pillBtn(true), padding:'5px 12px', fontSize:11}}>
                  ✓ Megjelent
                </button>
              </div>
            ))
          }
        </Card>
        <Card
          title={detailItem ? `Idővonal · ${detailItem.contact_name || 'Ügyfél'}` : 'Visszaellenőrzési idővonal'}
          subtitle="Kattints egy sorra a részletekért"
        >
          <Timeline events={detailTimeline}/>
        </Card>
      </div>

      <Card
        title="Összes kérés nyomon követése"
        subtitle="Küldés → megnyitás → kattintás → megerősítés"
        action={<Tabs items={['Mind','Kattintott','Nem nyitotta','Megjelent']} active={verifyTab} onChange={setVerifyTab}/>}
      >
        <VerificationTable items={tabItems} onDetail={item => { handleDetail(item); setDetailItem(item); }}/>
      </Card>

      {/* Reply modal */}
      {replyModal && (
        <div style={{position:'fixed', inset:0, background:'rgba(15,26,38,0.6)', zIndex:1000, display:'flex', alignItems:'center', justifyContent:'center'}}>
          <div style={{background:C.white, borderRadius:12, padding:28, width:400, border:`1px solid ${C.line}`}}>
            <div style={{fontFamily:'"DM Serif Display", serif', fontSize:18, color:C.navyDeep, marginBottom:16}}>
              Állapot módosítás — {replyModal.contact_name || replyModal.author || ''}
            </div>
            <div style={{fontSize:11, color:C.mute, marginBottom:6}}>Új állapot</div>
            <select
              value={replyState}
              onChange={e => setReplyState(e.target.value)}
              style={{width:'100%', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit', marginBottom:16}}
            >
              <option value="waiting">Várakozik (figyelés alatt)</option>
              <option value="sent">Kiküldve (visszaállítás)</option>
              <option value="published">Publikálva (lezárás)</option>
              <option value="disappeared">Eltűnt</option>
              <option value="failed">Sikertelen</option>
            </select>
            <div style={{display:'flex', gap:8, justifyContent:'flex-end'}}>
              <button onClick={() => { setReplyModal(null); setReplyState('waiting'); }} style={{...pillBtn(false), padding:'8px 16px'}}>Mégse</button>
              <button onClick={handleReply} style={{...pillBtn(true), padding:'8px 16px'}}>Mentés</button>
            </div>
          </div>
        </div>
      )}

      {/* Assign modal */}
      {assignModal && (
        <div style={{position:'fixed', inset:0, background:'rgba(15,26,38,0.6)', zIndex:1000, display:'flex', alignItems:'center', justifyContent:'center'}}>
          <div style={{background:C.white, borderRadius:12, padding:28, width:400, border:`1px solid ${C.line}`}}>
            <div style={{fontFamily:'"DM Serif Display", serif', fontSize:18, color:C.navyDeep, marginBottom:16}}>
              Ügynök hozzárendelése
            </div>
            <div style={{fontSize:11, color:C.mute, marginBottom:6}}>Ügynök</div>
            <select
              value={assignAgentId}
              onChange={e => setAssignAgentId(e.target.value)}
              style={{width:'100%', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit', marginBottom:16}}
            >
              <option value="">— válasszon —</option>
              {agents.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}
            </select>
            <div style={{display:'flex', gap:8, justifyContent:'flex-end'}}>
              <button onClick={() => { setAssignModal(null); setAssignAgentId(''); }} style={{...pillBtn(false), padding:'8px 16px'}}>Mégse</button>
              <button onClick={handleAssign} disabled={!assignAgentId} style={{...pillBtn(true), padding:'8px 16px'}}>Hozzárendel</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// =================== STATS VIEW ===================
function StatsView() {
  const w = useWindowWidth();
  const isMobile = w < 768;
  const [stats, setStats] = useState(null);
  useEffect(() => { apiFetch('api/stats.php').then(setStats).catch(() => {}); }, []);

  const kpi = stats?.kpi || {};

  return (
    <div style={{padding: isMobile ? 14 : 24, display:'flex', flexDirection:'column', gap: isMobile ? 12 : 18}}>
      {(() => {
        const funnel = (stats?.funnel_30d || []).map(x => x.requests || 0);
        const trend  = (stats?.recent_trend || []).map(x => x.count || 0);
        return (
        <div style={{display:'grid', gridTemplateColumns: isMobile ? '1fr 1fr' : 'repeat(4, 1fr)', gap: isMobile ? 10 : 12}}>
          <Stat label="Összes értékelés" value={kpi.total_reviews ?? '—'} accent={C.navy}
            spark={<Spark data={trend.length ? trend : [0]} color={C.navy}/>}/>
          <Stat label="Átlag csillag" value={kpi.avg_star ? kpi.avg_star.toFixed(2) : '—'} unit={kpi.avg_star ? '/5' : ''}
            accent={C.gold} spark={<Spark data={[kpi.avg_star||0]} color={C.gold}/>}/>
          <Stat label="Konverziós ráta" value={kpi.conversion_rate != null ? kpi.conversion_rate + '%' : '—'}
            accent={C.goldDeep} spark={<Spark data={funnel.length ? funnel : [0]} color={C.goldDeep}/>}/>
          <Stat label="Elküldött kérések" value={kpi.total_sent ?? '—'} accent={C.ok}
            spark={<Spark data={funnel.length ? funnel : [0]} color={C.ok}/>}/>
        </div>
        );
      })()}

      <div style={{display:'grid', gridTemplateColumns: isMobile ? '1fr' : '2fr 1fr', gap: isMobile ? 12 : 14}}>
        <Card title="Csillageloszlás · 12 hó" subtitle="Hónapról hónapra változás" action={<Tabs items={['12H','6H','3H']}/>}>
          <StackedBars data={stats?.star_distribution}/>
        </Card>
        <Card title="Konverziós tölcsér · 30 nap" subtitle="Kérés → Megnyitás → Publikált">
          {(() => {
            const f = stats?.funnel_30d || [];
            if (!f.length) return <div style={{padding:'20px 0', textAlign:'center', color:C.mute, fontSize:12}}>Nincs adat</div>;
            const tot = f.reduce((a,d) => ({
              requests:  a.requests  + (d.requests  || 0),
              opened:    a.opened    + (d.opened    || 0),
              published: a.published + (d.published || 0),
            }), {requests:0, opened:0, published:0});
            const pct = (n, d) => d > 0 ? Math.round(n / d * 100) : 0;
            const steps = [
              { label:'Kérés kiküldve',  value: tot.requests,  pct: 100,                        color: C.navy    },
              { label:'Email megnyitva', value: tot.opened,    pct: pct(tot.opened, tot.requests),    color: C.gold    },
              { label:'Publikált',       value: tot.published, pct: pct(tot.published, tot.requests), color: '#6E9C6E' },
            ];
            return (
              <div style={{display:'flex', flexDirection:'column', gap:16, paddingTop:4}}>
                {steps.map(s => (
                  <div key={s.label}>
                    <div style={{display:'flex', justifyContent:'space-between', marginBottom:6, alignItems:'baseline'}}>
                      <span style={{fontSize:12.5, color:C.navyDeep, fontWeight:600}}>{s.label}</span>
                      <span style={{fontSize:11, fontFamily:'"DM Mono", monospace', color:C.mute}}>
                        <span style={{color:C.navyDeep, fontWeight:700, fontSize:13}}>{s.value}</span>
                        {' · '}
                        <span style={{color:s.color, fontWeight:700}}>{s.pct}%</span>
                      </span>
                    </div>
                    <div style={{height:8, background:C.lineSoft, borderRadius:4, overflow:'hidden'}}>
                      <div style={{height:'100%', width: s.pct + '%', background:s.color, borderRadius:4, transition:'width 0.4s'}}/>
                    </div>
                  </div>
                ))}
                <div style={{fontSize:11, color:C.mute, marginTop:4, fontFamily:'"DM Mono", monospace'}}>
                  Konverzió: <span style={{color:'#6E9C6E', fontWeight:700}}>{pct(tot.published, tot.requests)}%</span>
                  {' · '}
                  Megnyitási arány: <span style={{color:C.gold, fontWeight:700}}>{pct(tot.opened, tot.requests)}%</span>
                </div>
              </div>
            );
          })()}
        </Card>
      </div>

      <Card title="Ügynök leaderboard" subtitle="Kérések, publikált értékelések, átlag csillag">
        {(() => {
          const lb = stats?.agent_leaderboard || [];
          if (!lb.length) return <div style={{padding:'20px 0', textAlign:'center', color:C.mute, fontSize:12}}>Nincs adat</div>;
          const statusColor = { top:'#6E9C6E', rising:C.gold, attention:C.bad, stable:C.navy };
          return (
            <div>
              <div style={{display:'grid', gridTemplateColumns:'1.5fr 0.6fr 0.6fr 0.7fr 0.7fr 0.6fr', gap:10, padding:'4px 4px 8px', fontSize:10, fontFamily:'"DM Mono", monospace', color:C.mute, letterSpacing:0.8, borderBottom:`1px solid ${C.lineSoft}`}}>
                <div>ÜGYNÖK</div><div style={{textAlign:'right'}}>KÉRÉS</div><div style={{textAlign:'right'}}>PUBLIKÁLT</div><div style={{textAlign:'right'}}>ÁTL.★</div><div style={{textAlign:'right'}}>KONV.</div><div style={{textAlign:'right'}}>STÁTUSZ</div>
              </div>
              {lb.map((a, i) => (
                <div key={i} style={{display:'grid', gridTemplateColumns:'1.5fr 0.6fr 0.6fr 0.7fr 0.7fr 0.6fr', gap:10, padding:'10px 4px', fontSize:12, color:C.navy, alignItems:'center', borderBottom: i<lb.length-1?`1px solid ${C.lineSoft}`:'none'}}>
                  <div style={{fontWeight:600, color:C.navyDeep}}>{a.agent_name}</div>
                  <div style={{textAlign:'right', fontFamily:'"DM Mono", monospace'}}>{a.requests}</div>
                  <div style={{textAlign:'right', fontFamily:'"DM Mono", monospace'}}>{a.reviews}</div>
                  <div style={{textAlign:'right', fontFamily:'"DM Mono", monospace', color:C.gold, fontWeight:600}}>{a.avg_star ? Number(a.avg_star).toFixed(2) : '–'}</div>
                  <div style={{textAlign:'right', fontFamily:'"DM Mono", monospace', fontWeight:600, color: a.conv>=65?C.ok:C.navyDeep}}>{a.conv ? a.conv + '%' : '–'}</div>
                  <div style={{textAlign:'right'}}>
                    <span style={{fontSize:9.5, padding:'2px 6px', borderRadius:4, background: statusColor[a.status]||C.mute, color:'#fff', fontFamily:'"DM Mono", monospace', fontWeight:700}}>
                      {a.status?.toUpperCase() || '–'}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          );
        })()}
      </Card>
    </div>
  );
}

// =================== INBOX VIEW ===================
function InboxView() {
  const w = useWindowWidth();
  const isMobile = w < 768;
  const [items, setItems] = useState([]);
  const [filter, setFilter] = useState('all');
  const [loading, setLoading] = useState(true);
  const [replyModal, setReplyModal] = useState(null); // {item, replyText, newState}
  const [replyText, setReplyText] = useState('');
  const [newState, setNewState] = useState('');
  const [assignModal, setAssignModal] = useState(null);
  const [agents, setAgents] = useState([]);
  const [assignAgentId, setAssignAgentId] = useState('');
  const [autoModal, setAutoModal] = useState(null); // {contact_id, contact_name}
  const [autoModalAutos, setAutoModalAutos] = useState([]);
  const [autoModalSel, setAutoModalSel] = useState('');
  const [autoModalLoading, setAutoModalLoading] = useState(false);

  const [allItems, setAllItems] = useState([]);

  const load = () => {
    setLoading(true);
    apiFetch('api/inbox.php?type=requests&per_page=100')
      .then(d => { setAllItems(d.items || d.data || []); setLoading(false); })
      .catch(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);
  useEffect(() => { apiFetch('api/agents.php').then(d => setAgents(d.data || [])).catch(() => {}); }, []);
  useEffect(() => { apiFetch('api/automations.php').then(d => setAutoModalAutos(d.automations || d.data || [])).catch(() => {}); }, []);

  const openReply = (item) => {
    setReplyText('');
    setNewState(item.state || 'sent');
    setReplyModal(item);
  };

  const handleReply = () => {
    if (!replyModal) return;
    const body = replyModal.type === 'review'
      ? { reply_text: replyText }
      : { state: newState };
    apiFetch(`api/inbox.php?id=${replyModal.id}&type=${replyModal.type}`, {
      method: 'PUT',
      body: JSON.stringify(body)
    })
      .then(d => {
        if (d.error) { alert(d.error); return; }
        setReplyModal(null);
        load();
      })
      .catch(e => alert('Mentés sikertelen: ' + (e?.message || 'ismeretlen hiba')));
  };

  const handleAssign = () => {
    if (!assignModal || !assignAgentId) return;
    apiFetch(`api/inbox.php?id=${assignModal.id}&type=request`, {
      method: 'PUT',
      body: JSON.stringify({ agent_id: parseInt(assignAgentId) })
    }).then(() => { setAssignModal(null); load(); });
  };

  const slaColors = { breach: C.bad, 'sla-due': C.warn, ok: C.ok };

  const filters = [
    ['mind',       'Mind',       () => true],
    ['fuggőben',   'Függőben',   it => !it.published_at && !it.clicked_at],
    ['kattintott', 'Kattintott', it => !!it.clicked_at && !it.published_at],
    ['publikalt',  'Publikált',  it => !!it.published_at],
  ];

  const filteredItems = filter === 'mind'
    ? allItems
    : allItems.filter(filters.find(f => f[0] === filter)?.[2] || (() => true));

  return (
    <div style={{padding: isMobile ? 14 : 24, display:'flex', flexDirection:'column', gap: isMobile ? 12 : 18}}>
      {/* Filter tabs */}
      <div style={{display:'flex', gap:8, flexWrap:'wrap'}}>
        {filters.map(([v, l, fn]) => {
          const cnt = v === 'mind' ? allItems.length : allItems.filter(fn).length;
          return (
            <button key={v} onClick={() => setFilter(v)} style={{...pillBtn(filter === v), padding:'6px 14px', display:'flex', alignItems:'center', gap:6}}>
              {l}
              <span style={{fontSize:10, fontFamily:'"DM Mono", monospace', opacity:0.7}}>{cnt}</span>
            </button>
          );
        })}
      </div>

      {loading ? <LoadingState/> : (
        <div style={{display:'flex', flexDirection:'column', gap:10}}>
          {filteredItems.length === 0 && (
            <div style={{padding:40, textAlign:'center', color:C.mute, fontSize:13}}>
              Nincs elem ebben a nézetben.
            </div>
          )}
          {filteredItems.map((item, i) => item.type === 'request' ? (
            // ── REQUEST CARD ──
            <div key={i} style={{
              background: C.white, border:`1px solid ${C.lineSoft}`,
              borderLeft: `3px solid ${item.published_at ? C.ok : item.clicked_at ? C.gold : item.opened_at ? C.navy : C.line}`,
              borderRadius:8, padding:'14px 16px'
            }}>
              <div style={{display:'flex', alignItems:'center', gap:8, marginBottom:8}}>
                <div style={{width:28,height:28,borderRadius:'50%',background:C.creamSoft,display:'flex',alignItems:'center',justifyContent:'center',fontSize:10,fontWeight:700,color:C.navyDeep,border:`1px solid ${C.line}`,flexShrink:0}}>
                  {(item.contact_name||'?').split(' ').map(w=>w[0]).join('').slice(0,2)}
                </div>
                <div style={{flex:1, minWidth:0}}>
                  <div style={{fontSize:13, fontWeight:700, color:C.navyDeep}}>{item.contact_name}</div>
                  <div style={{fontSize:11, color:C.mute}}>{item.excerpt/* email */}</div>
                </div>
                <span style={{fontSize:10, padding:'2px 7px', borderRadius:4, background:C.creamSoft, color:C.navy, fontFamily:'"DM Mono", monospace', fontWeight:600}}>
                  {(item.channel||'email').toUpperCase()}
                </span>
                {item.days_since_contact != null && (() => {
                  const d = item.days_since_contact;
                  const col = d >= 30 ? C.bad : d >= 7 ? C.warn : C.ok;
                  const bg  = d >= 30 ? '#F6E5E3' : d >= 7 ? '#FAF1DF' : '#E8F1E5';
                  return (
                    <span title="Utolsó kapcsolatfelvétel" style={{
                      fontSize:10, padding:'2px 7px', borderRadius:4,
                      background:bg, color:col,
                      fontFamily:'"DM Mono", monospace', fontWeight:700,
                      border:`1px solid ${col}44`, whiteSpace:'nowrap'
                    }}>
                      {d === 0 ? 'ma' : `${d}n`}
                    </span>
                  );
                })()}
                <span style={{fontSize:10.5, color:C.mute, fontFamily:'"DM Mono", monospace'}}>{item.time_since}</span>
              </div>

              {/* Tracking steps */}
              <div style={{display:'flex', gap:6, marginBottom:10, paddingLeft:36}}>
                {[
                  ['Elküldve',   item.sent_at,      C.navy ],
                  ['Megnyitva',  item.opened_at,    C.gold ],
                  ['Kattintott', item.clicked_at,   C.goldDeep],
                  ['Publikált',  item.published_at, C.ok   ],
                ].map(([lbl, ts, col]) => (
                  <div key={lbl} style={{
                    display:'flex', alignItems:'center', gap:4, fontSize:10.5,
                    padding:'3px 8px', borderRadius:5,
                    background: ts ? col + '18' : C.creamSoft,
                    color: ts ? col : C.mute,
                    fontFamily:'"DM Mono", monospace', fontWeight: ts ? 700 : 400,
                    border: `1px solid ${ts ? col + '44' : C.line}`
                  }}>
                    <span>{ts ? '✓' : '○'}</span> {lbl}
                  </div>
                ))}
              </div>

              <div style={{paddingLeft:36, display:'flex', alignItems:'center', gap:8, flexWrap:'wrap'}}>
                <span style={{fontSize:10, color:C.mute, fontFamily:'"DM Mono", monospace'}}>
                  {item.agent_name && `${item.agent_name}`}
                  {item.state && ` · ${item.state.toUpperCase()}`}
                </span>
                {item.automation_name && (
                  <span style={{fontSize:10, padding:'2px 7px', borderRadius:4, background:'#EEF2FF', color:'#4455AA', fontFamily:'"DM Mono", monospace', fontWeight:600, border:'1px solid #C8D0EE'}}>
                    ⚡ {item.automation_name}
                  </span>
                )}
                {item.active_automation_state && (
                  <span title={`Folyamatban: ${item.active_automation_name || 'automatizmus'}`} style={{
                    fontSize:10, padding:'2px 7px', borderRadius:4,
                    background:'#FFF3CD', color:'#8A5F00',
                    fontFamily:'"DM Mono", monospace', fontWeight:700,
                    border:'1px solid #F0D080',
                    display:'flex', alignItems:'center', gap:4
                  }}>
                    <span className="pulse-dot" style={{
                      width:6, height:6, borderRadius:'50%', background:'#E09800',
                      display:'inline-block'
                    }}/>
                    {item.active_automation_name
                      ? item.active_automation_name + ' · folyamatban'
                      : 'Automatizmus fut'}
                  </span>
                )}
                <span style={{flex:1}}/>
                <button onClick={() => { setAssignAgentId(''); setAssignModal(item); }} style={{...pillBtn(false), padding:'4px 10px', fontSize:11}}>Ügynök</button>
                <button
                  disabled={!!item.active_automation_state}
                  title={item.active_automation_state ? `Fut: ${item.active_automation_name || 'automatizmus'} — előbb fejezze be` : 'Automatizmus indítása'}
                  onClick={() => { if(!item.active_automation_state){ setAutoModalSel(''); setAutoModal({contact_id: item.contact_id, contact_name: item.contact_name}); } }}
                  style={{...pillBtn(false), padding:'4px 10px', fontSize:11, opacity: item.active_automation_state ? 0.4 : 1, cursor: item.active_automation_state ? 'not-allowed' : 'pointer'}}
                >+ Auto</button>
                <button onClick={() => openReply(item)} style={{...pillBtn(true), padding:'4px 10px', fontSize:11}}>Állapot</button>
              </div>
            </div>
          ) : (
            // ── REVIEW CARD ──
            <div key={i} style={{
              background: C.white, border:`1px solid ${C.lineSoft}`,
              borderLeft:`3px solid ${slaColors[item.sla_status]||C.line}`,
              borderRadius:8, padding:'14px 16px'
            }}>
              <div style={{display:'flex', alignItems:'center', gap:8, marginBottom:6}}>
                <div style={{width:28,height:28,borderRadius:'50%',background:C.creamSoft,display:'flex',alignItems:'center',justifyContent:'center',fontSize:10,fontWeight:700,color:C.navyDeep,border:`1px solid ${C.line}`}}>
                  {(item.contact_name||'?').split(' ').map(w=>w[0]).join('').slice(0,2)}
                </div>
                <span style={{fontSize:12.5, fontWeight:600, color:C.navyDeep}}>{item.contact_name}</span>
                {item.star > 0 && <Stars value={item.star}/>}
                <span style={{fontSize:10, color:C.mute, fontFamily:'"DM Mono", monospace', padding:'2px 6px', background:C.creamSoft, borderRadius:4}}>értékelés</span>
                <span style={{flex:1}}/>
                {item.sla_status && item.sla_status !== 'ok' && (
                  <span style={{fontSize:9.5,padding:'2px 6px',borderRadius:4,background:slaColors[item.sla_status],color:'#fff',fontFamily:'"DM Mono", monospace',fontWeight:700}}>
                    {item.sla_status === 'breach' ? 'SLA SÉRTÉS' : 'SLA KÖZEL'}
                  </span>
                )}
                <span style={{fontSize:10.5, color:C.mute, fontFamily:'"DM Mono", monospace'}}>{item.time_since}</span>
              </div>
              {item.excerpt && (
                <div style={{fontSize:11.5,color:C.navy,lineHeight:1.5,marginBottom:8,paddingLeft:36,fontStyle:'italic'}}>
                  „{item.excerpt.slice(0,160)}{item.excerpt.length>160?'…':''}"
                </div>
              )}
              <div style={{paddingLeft:36, display:'flex', alignItems:'center', gap:8}}>
                <span style={{fontSize:10,color:C.mute,fontFamily:'"DM Mono", monospace'}}>
                  {item.agent_name && `Ügynök: ${item.agent_name}`}
                  {item.office_name && ` · ${item.office_name}`}
                </span>
                <span style={{flex:1}}/>
                <button onClick={() => openReply(item)} style={{...pillBtn(true), padding:'4px 10px', fontSize:11}}>Válasz</button>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Reply / state modal */}
      {replyModal && (
        <div style={{position:'fixed', inset:0, background:'rgba(15,26,38,0.6)', zIndex:1000, display:'flex', alignItems:'center', justifyContent:'center'}}>
          <div style={{background:C.white, borderRadius:12, padding:28, width:480, border:`1px solid ${C.line}`}}>
            <div style={{fontFamily:'"DM Serif Display", serif', fontSize:18, color:C.navyDeep, marginBottom:16}}>
              {replyModal.type === 'review' ? 'Válasz' : 'Állapot módosítása'} — {replyModal.contact_name}
            </div>
            {replyModal.type === 'review' ? (
              <textarea
                value={replyText}
                onChange={e => setReplyText(e.target.value)}
                style={{width:'100%', padding:'10px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit', minHeight:120, resize:'vertical', boxSizing:'border-box'}}
                placeholder="Válasz szövege..."
              />
            ) : (
              <select
                value={newState}
                onChange={e => setNewState(e.target.value)}
                style={{width:'100%', padding:'10px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}
              >
                {['pending','sent','waiting','published','disappeared','failed'].map(s => (
                  <option key={s} value={s}>{s}</option>
                ))}
              </select>
            )}
            <div style={{display:'flex', gap:8, justifyContent:'flex-end', marginTop:14}}>
              <button onClick={() => setReplyModal(null)} style={{...pillBtn(false), padding:'8px 16px'}}>Mégse</button>
              <button onClick={handleReply} style={{...pillBtn(true), padding:'8px 16px'}}>Mentés</button>
            </div>
          </div>
        </div>
      )}

      {/* Assign modal */}
      {assignModal && (
        <div style={{position:'fixed', inset:0, background:'rgba(15,26,38,0.6)', zIndex:1000, display:'flex', alignItems:'center', justifyContent:'center'}}>
          <div style={{background:C.white, borderRadius:12, padding:28, width:360, border:`1px solid ${C.line}`}}>
            <div style={{fontFamily:'"DM Serif Display", serif', fontSize:18, color:C.navyDeep, marginBottom:16}}>
              Ügynök hozzárendelése
            </div>
            <select
              value={assignAgentId}
              onChange={e => setAssignAgentId(e.target.value)}
              style={{width:'100%', padding:'10px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit', marginBottom:14}}
            >
              <option value="">— Válassz ügynököt —</option>
              {agents.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}
            </select>
            <div style={{display:'flex', gap:8, justifyContent:'flex-end'}}>
              <button onClick={() => setAssignModal(null)} style={{...pillBtn(false), padding:'8px 16px'}}>Mégse</button>
              <button onClick={handleAssign} disabled={!assignAgentId} style={{...pillBtn(true), padding:'8px 16px', opacity: assignAgentId ? 1 : 0.5}}>Mentés</button>
            </div>
          </div>
        </div>
      )}

      {/* Run automation modal */}
      {autoModal && (
        <div style={{position:'fixed',inset:0,background:'rgba(15,26,38,0.6)',zIndex:1000,display:'flex',alignItems:'center',justifyContent:'center'}}>
          <div style={{background:C.white,borderRadius:12,padding:28,width:440,maxWidth:'90vw',border:`1px solid ${C.line}`}}>
            <div style={{fontFamily:'"DM Serif Display",serif',fontSize:18,color:C.navyDeep,marginBottom:6}}>Automatizmus indítása</div>
            <div style={{fontSize:12,color:C.mute,marginBottom:20}}>{autoModal.contact_name}</div>
            <div style={{marginBottom:20}}>
              <div style={{fontSize:11,color:C.mute,marginBottom:6}}>Válasszon automatizmust</div>
              <select value={autoModalSel} onChange={e=>setAutoModalSel(e.target.value)}
                style={{width:'100%',padding:'10px 12px',borderRadius:6,border:`1px solid ${C.line}`,fontSize:13,fontFamily:'inherit'}}>
                <option value="">— válasszon —</option>
                {autoModalAutos.filter(a=>a.active===1||a.active===true).map(a=>(
                  <option key={a.id} value={a.id}>{a.name}</option>
                ))}
              </select>
              {autoModalSel && (() => {
                const a = autoModalAutos.find(x=>String(x.id)===String(autoModalSel));
                return a ? (
                  <div style={{marginTop:8,padding:'8px 12px',background:C.creamSoft,borderRadius:6,fontSize:11.5,color:C.navy,lineHeight:1.7}}>
                    <span style={{color:C.mute}}>Csatorna:</span> {a.channel||'–'}
                    {a.delay_hours>0 && <> · <span style={{color:C.mute}}>Késleltetés:</span> {a.delay_hours}ó</>}
                  </div>
                ) : null;
              })()}
            </div>
            <div style={{display:'flex',gap:8,justifyContent:'flex-end'}}>
              <button onClick={()=>setAutoModal(null)} style={{...pillBtn(false),padding:'8px 16px'}}>Mégse</button>
              <button
                disabled={!autoModalSel||autoModalLoading}
                onClick={()=>{
                  setAutoModalLoading(true);
                  apiFetch('api/requests.php?action=run',{method:'POST',body:JSON.stringify({
                    contact_id: autoModal.contact_id,
                    automation_id: parseInt(autoModalSel),
                  })})
                  .then(d=>{
                    if(d.error) throw new Error(d.error);
                    setAutoModal(null);
                    load();
                  })
                  .catch(e=>alert('Hiba: '+(e.message||'ismeretlen')))
                  .finally(()=>setAutoModalLoading(false));
                }}
                style={{...pillBtn(true),padding:'8px 16px'}}
              >
                {autoModalLoading ? 'Indítás...' : 'Indítás →'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// =================== TEMPLATES VIEW ===================
function TemplatesView() {
  const w = useWindowWidth();
  const isMobile = w < 768;
  const [templates, setTemplates] = useState([]);
  const [editModal, setEditModal] = useState(null);
  const [bodyTab, setBodyTab] = useState('preview');
  const [loading, setLoading] = useState(true);

  const load = () => {
    setLoading(true);
    apiFetch('api/templates.php')
      .then(d => { setTemplates(d.data || d); setLoading(false); })
      .catch(() => setLoading(false));
  };
  useEffect(() => load(), []);

  const save = () => {
    const method = editModal.id ? 'PUT' : 'POST';
    const url = editModal.id ? `api/templates.php?id=${editModal.id}` : 'api/templates.php';
    apiFetch(url, { method, body: JSON.stringify(editModal) })
      .then(() => { setEditModal(null); load(); });
  };

  const del = (id) => {
    if (!confirm('Sablon törlése?')) return;
    apiFetch(`api/templates.php?id=${id}`, { method: 'DELETE' }).then(load);
  };

  const channelColors = {
    email: { bg:'#E8F1E5', fg:C.ok },
    sms: { bg:'#FAF1DF', fg:C.goldDeep },
    'mindkettő': { bg:C.creamSoft, fg:C.navy }
  };

  return (
    <div style={{padding: isMobile ? 14 : 24}}>
      <div style={{display:'flex', justifyContent:'flex-end', marginBottom:16}}>
        <button
          onClick={() => setEditModal({ name:'', channel:'email', subject:'', body_html:'', body_text:'' })}
          style={{...pillBtn(true), padding:'8px 16px'}}
        >
          <Ico.plus/> Új sablon
        </button>
      </div>

      {loading ? <LoadingState/> : (
        <div style={{display:'grid', gridTemplateColumns: isMobile ? '1fr' : 'repeat(3, 1fr)', gap:14}}>
          {templates.map((t, i) => {
            const cc = channelColors[t.channel] || channelColors['mindkettő'];
            return (
              <div key={i} style={{background:C.white, border:`1px solid ${C.line}`, borderRadius:10, padding:16}}>
                <div style={{display:'flex', justifyContent:'space-between', marginBottom:10}}>
                  <span style={{
                    fontSize:9.5, padding:'2px 7px', borderRadius:4,
                    background:cc.bg, color:cc.fg,
                    fontFamily:'"DM Mono", monospace', fontWeight:700
                  }}>
                    {(t.channel || '').toUpperCase()}
                  </span>
                  <div style={{display:'flex', gap:6}}>
                    <button onClick={() => apiFetch(`api/templates.php?id=${t.id}`).then(full => setEditModal({...full}))} style={{...pillBtn(false), padding:'3px 8px', fontSize:10}}>Szerk.</button>
                    <button onClick={() => del(t.id)} style={{background:'transparent', border:'none', color:C.bad, cursor:'pointer', fontSize:14, lineHeight:1}}>×</button>
                  </div>
                </div>
                <div style={{fontSize:13, fontWeight:600, color:C.navyDeep, marginBottom:4}}>{t.name}</div>
                <div style={{fontSize:11.5, color:C.mute, marginBottom:8}}>{t.subject}</div>
                <div style={{fontSize:11, color:C.navy, lineHeight:1.5, overflow:'hidden', maxHeight:60}}>
                  {(t.body_text || '').slice(0, 100)}{t.body_text && t.body_text.length > 100 ? '...' : ''}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Edit modal */}
      {editModal && (
        <div style={{position:'fixed', inset:0, background:'rgba(15,26,38,0.6)', zIndex:1000, display:'flex', alignItems:'center', justifyContent:'center'}}>
          <div style={{
            background:C.white, borderRadius:12, padding:28, width:700, maxWidth:'95vw',
            border:`1px solid ${C.line}`, maxHeight:'92vh', overflowY:'auto'
          }}>
            <div style={{fontFamily:'"DM Serif Display", serif', fontSize:18, color:C.navyDeep, marginBottom:20}}>
              {editModal.id ? 'Sablon szerkesztése' : 'Új sablon'}
            </div>

            {/* Name + Subject */}
            {[['Sablon neve', 'name'], ['Tárgysor', 'subject']].map(([l, k]) => (
              <div key={k} style={{marginBottom:12}}>
                <div style={{fontSize:11, color:C.mute, marginBottom:4}}>{l}</div>
                <input
                  type="text"
                  value={editModal[k] || ''}
                  onChange={e => setEditModal(m => ({...m, [k]: e.target.value}))}
                  style={{width:'100%', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit', boxSizing:'border-box'}}
                />
              </div>
            ))}

            {/* Channel */}
            <div style={{marginBottom:16}}>
              <div style={{fontSize:11, color:C.mute, marginBottom:4}}>Csatorna</div>
              <select
                value={editModal.channel || 'email'}
                onChange={e => setEditModal(m => ({...m, channel: e.target.value}))}
                style={{width:'100%', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}
              >
                <option value="email">Email</option>
                <option value="sms">SMS</option>
                <option value="mindkettő">Email + SMS</option>
              </select>
            </div>

            {/* Body — SMS: plain textarea, Email: preview/code tabs */}
            {editModal.channel === 'sms' ? (
              <div style={{marginBottom:16}}>
                <div style={{fontSize:11, color:C.mute, marginBottom:4}}>
                  SMS szöveg
                  <span style={{float:'right', fontFamily:'"DM Mono", monospace'}}>{(editModal.body_text||'').length} kar</span>
                </div>
                <textarea
                  value={editModal.body_text || ''}
                  onChange={e => setEditModal(m => ({...m, body_text: e.target.value}))}
                  style={{
                    width:'100%', padding:'10px 12px', borderRadius:6, border:`1px solid ${C.line}`,
                    fontSize:13, fontFamily:'inherit', minHeight:100, resize:'vertical', boxSizing:'border-box', lineHeight:1.6
                  }}
                />
              </div>
            ) : (
              <div style={{marginBottom:16}}>
                {/* Tab bar */}
                <div style={{display:'flex', gap:0, marginBottom:0, borderBottom:`1px solid ${C.line}`}}>
                  {[['preview','Előnézet'],['html','HTML kód'],['text','Szöveges']].map(([v,l]) => (
                    <button key={v} onClick={() => setBodyTab(v)} style={{
                      padding:'7px 16px', fontSize:11.5, fontWeight: bodyTab===v?700:400,
                      color: bodyTab===v ? C.navyDeep : C.mute,
                      background:'transparent', border:'none', cursor:'pointer',
                      borderBottom: bodyTab===v ? `2px solid ${C.gold}` : '2px solid transparent',
                      marginBottom:-1
                    }}>{l}</button>
                  ))}
                </div>

                {bodyTab === 'preview' && (
                  <div style={{border:`1px solid ${C.line}`, borderTop:'none', borderRadius:'0 0 6px 6px', overflow:'hidden', height:380}}>
                    {editModal.body_html ? (
                      <iframe
                        srcDoc={editModal.body_html}
                        sandbox="allow-same-origin"
                        style={{width:'100%', height:'100%', border:'none', background:'#fff'}}
                        title="email-preview"
                      />
                    ) : (
                      <div style={{padding:40, textAlign:'center', color:C.mute, fontSize:12}}>Nincs HTML tartalom</div>
                    )}
                  </div>
                )}

                {bodyTab === 'html' && (
                  <div style={{border:`1px solid ${C.line}`, borderTop:'none', borderRadius:'0 0 6px 6px'}}>
                    <textarea
                      value={editModal.body_html || ''}
                      onChange={e => setEditModal(m => ({...m, body_html: e.target.value}))}
                      style={{
                        display:'block', width:'100%', padding:'10px 12px',
                        borderRadius:'0 0 6px 6px', border:'none',
                        fontSize:11.5, fontFamily:'"DM Mono", monospace',
                        minHeight:380, resize:'vertical', boxSizing:'border-box',
                        lineHeight:1.5, background:'#F9F9F7', outline:'none'
                      }}
                    />
                  </div>
                )}

                {bodyTab === 'text' && (
                  <div style={{border:`1px solid ${C.line}`, borderTop:'none', borderRadius:'0 0 6px 6px'}}>
                    <textarea
                      value={editModal.body_text || ''}
                      onChange={e => setEditModal(m => ({...m, body_text: e.target.value}))}
                      style={{
                        display:'block', width:'100%', padding:'10px 12px',
                        borderRadius:'0 0 6px 6px', border:'none',
                        fontSize:13, fontFamily:'inherit', minHeight:200,
                        resize:'vertical', boxSizing:'border-box', lineHeight:1.7, outline:'none'
                      }}
                    />
                  </div>
                )}
              </div>
            )}

            {/* Variables hint */}
            <div style={{fontSize:11, color:C.mute, marginBottom:16, lineHeight:1.8}}>
              Változók:{' '}
              {['{ügyfél_keresztnév}','{ügynök_neve}','{ügynök_telefon}','{review_link}','{iroda_neve}','{dátum}'].map(v => (
                <code key={v} style={{fontFamily:'"DM Mono", monospace', background:C.creamSoft, padding:'1px 5px', borderRadius:3, marginRight:4}}>{v}</code>
              ))}
            </div>

            <div style={{display:'flex', gap:8, justifyContent:'flex-end'}}>
              <button onClick={() => setEditModal(null)} style={{...pillBtn(false), padding:'8px 16px'}}>Mégse</button>
              <button onClick={save} style={{...pillBtn(true), padding:'8px 16px'}}>Mentés</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// =================== CAMPAIGNS VIEW ===================
function CampaignsView() {
  const w = useWindowWidth();
  const isMobile = w < 768;
  const [requests, setRequests] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    apiFetch('api/requests.php?page=1&per_page=50')
      .then(d => { setRequests(d.requests || d.data || []); setLoading(false); })
      .catch(() => setLoading(false));
  }, []);

  // Group by day
  const groups = {};
  (requests || []).forEach(r => {
    const day = (r.sent_at || r.created_at || '').slice(0, 10);
    if (!groups[day]) groups[day] = [];
    groups[day].push(r);
  });

  const publishedCount = requests.filter(r => r.state === 'published').length;
  const convPct = requests.length
    ? Math.round((publishedCount / requests.length) * 100) + '%'
    : '—';

  const stateColors = {
    published: '#E8F1E5',
    sent: '#FAF1DF',
    internal: '#F6E5E3',
    pending: C.creamSoft
  };

  return (
    <div style={{padding: isMobile ? 14 : 24}}>
      <div style={{display:'grid', gridTemplateColumns: isMobile ? '1fr 1fr' : 'repeat(4, 1fr)', gap: isMobile ? 10 : 14, marginBottom:18}}>
        <Stat
          label="Összes kampány"
          value={Object.keys(groups).length.toString() || '0'}
          delta="Aktív"
          deltaPos
          accent={C.navy}
          spark={<Spark data={[1,2,3,4,5,6,7]} color={C.navy}/>}
        />
        <Stat
          label="Kiküldött kérés"
          value={requests.length.toString()}
          delta="30N"
          deltaPos
          accent={C.gold}
          spark={<Spark data={[5,8,12,15,18,22,Math.max(requests.length, 1)]} color={C.gold}/>}
        />
        <Stat
          label="Publikált"
          value={publishedCount.toString()}
          delta="értékelés"
          deltaPos
          accent={C.ok}
          spark={<Spark data={[2,4,6,8,10,12,Math.max(publishedCount, 1)]} color={C.ok}/>}
        />
        <Stat
          label="Konverzió"
          value={convPct}
          delta="átlag"
          deltaPos
          accent={C.goldDeep}
          spark={<Spark data={[30,35,38,40,42,38,40]} color={C.goldDeep}/>}
        />
      </div>

      <Card title="Küldési napló" subtitle="Kérések napok szerint csoportosítva">
        {loading ? <LoadingState/> : (
          Object.entries(groups).length === 0
            ? <div style={{padding:40, textAlign:'center', color:C.mute, fontSize:13}}>Nincs adat.</div>
            : Object.entries(groups)
                .sort((a, b) => b[0].localeCompare(a[0]))
                .map(([day, items]) => (
                  <div key={day} style={{marginBottom:16, paddingBottom:16, borderBottom:`1px solid ${C.lineSoft}`}}>
                    <div style={{fontSize:11, fontFamily:'"DM Mono", monospace', color:C.mute, marginBottom:10, letterSpacing:0.8}}>
                      {day}
                    </div>
                    <div style={{display:'flex', gap:8, flexWrap:'wrap'}}>
                      {items.map((r, i) => (
                        <div key={i} style={{
                          padding:'4px 10px', borderRadius:6,
                          background: stateColors[r.state] || C.creamSoft,
                          border:`1px solid ${C.line}`, fontSize:11
                        }}>
                          <span style={{fontWeight:600, color:C.navyDeep}}>{r.contact_name || '—'}</span>
                          <span style={{color:C.mute, marginLeft:6, fontFamily:'"DM Mono", monospace', fontSize:10}}>
                            {r.state}
                          </span>
                        </div>
                      ))}
                    </div>
                  </div>
                ))
        )}
      </Card>
    </div>
  );
}

// =================== SETTINGS VIEW ===================
function SettingsView() {
  const w = useWindowWidth();
  const isMobile = w < 768;
  const [config, setConfig] = useState(null);
  const [logs, setLogs] = useState([]);
  const [cronStatus, setCronStatus] = useState(null);
  const [testEmail, setTestEmail] = useState('');
  const [testPhone, setTestPhone] = useState('');
  const [testMsg, setTestMsg] = useState('');
  const [smsMsg, setSmsMsg] = useState('');
  const [loading, setLoading] = useState(true);
  const [savingSmtp, setSavingSmtp] = useState(false);
  const [savingTwilio, setSavingTwilio] = useState(false);
  const [savingGoogle, setSavingGoogle] = useState(false);
  const [smtpSaveMsg, setSmtpSaveMsg] = useState('');
  const [twilioSaveMsg, setTwilioSaveMsg] = useState('');
  const [googleSaveMsg, setGoogleSaveMsg] = useState('');
  const [smtpForm, setSmtpForm] = useState({ smtp_host:'', smtp_user:'', smtp_pass:'', smtp_port:'587', smtp_from_name:'', smtp_secure:'tls' });
  const [twilioForm, setTwilioForm] = useState({ twilio_sid:'', twilio_token:'', twilio_from:'' });
  const [googleForm, setGoogleForm] = useState({ google_api_key:'' });
  const [users, setUsers] = useState([]);
  const [userModal, setUserModal] = useState(null);
  const [userErr, setUserErr] = useState('');
  const [userForm, setUserForm] = useState({ name:'', email:'', password:'', role:'agent', office_id:'' });

  useEffect(() => {
    Promise.all([
      apiFetch('api/settings.php'),
      apiFetch('api/settings.php?action=logs&lines=20'),
      apiFetch('api/settings.php?action=cron_status'),
      apiFetch('api/users.php'),
    ]).then(([c, l, cs, u]) => {
      setConfig(c);
      setLogs(l.logs || []);
      setCronStatus(cs.crons || {});
      setUsers(u.data || []);
      if (c?.smtp) {
        setSmtpForm(f => ({
          ...f,
          smtp_host: c.smtp.host || '',
          smtp_user: c.smtp.user || '',
          smtp_port: String(c.smtp.port || '587'),
          smtp_from_name: c.smtp.from_name || '',
          smtp_secure: c.smtp.secure || 'tls',
        }));
      }
      if (c?.twilio) {
        setTwilioForm(f => ({
          ...f,
          twilio_from: c.twilio.from || '',
        }));
      }
      setLoading(false);
    }).catch(() => setLoading(false));
  }, []);

  const openNewUser = () => {
    setUserForm({ name:'', email:'', password:'', role:'agent', office_id:'' });
    setUserErr('');
    setUserModal({});
  };
  const openEditUser = (u) => {
    setUserForm({ name:u.name, email:u.email, password:'', role:u.role, office_id:u.office_id || '' });
    setUserErr('');
    setUserModal(u);
  };
  const saveUser = () => {
    const isNew = !userModal.id;
    const url   = isNew ? 'api/users.php' : `api/users.php?id=${userModal.id}`;
    const body  = { ...userForm };
    if (!body.password && !isNew) delete body.password;
    if (!body.office_id) delete body.office_id;
    apiFetch(url, { method: isNew ? 'POST' : 'PUT', body: JSON.stringify(body) })
      .then(d => {
        if (d.error) { setUserErr(d.error); return; }
        apiFetch('api/users.php').then(u => setUsers(u.data || []));
        setUserModal(null);
      })
      .catch(e => setUserErr(String(e)));
  };
  const deactivateUser = (uid) => {
    if (!confirm('Deaktiválja ezt a felhasználót?')) return;
    apiFetch(`api/users.php?id=${uid}`, { method:'DELETE' })
      .then(() => apiFetch('api/users.php').then(u => setUsers(u.data || [])));
  };

  const saveConfig = (payload, setMsg, setSaving) => {
    setSaving(true); setMsg('');
    apiFetch('api/settings.php?action=save_config', { method:'POST', body: JSON.stringify(payload) })
      .then(r => setMsg(r.success ? ('✓ ' + (r.message || 'Mentve')) : ('✗ ' + (r.error || 'Hiba'))))
      .catch(e => setMsg('✗ ' + String(e)))
      .finally(() => setSaving(false));
  };

  const testSmtp = () => {
    if (!testEmail) return;
    apiFetch('api/settings.php?action=test_smtp', {
      method: 'POST',
      body: JSON.stringify({ test_email: testEmail })
    }).then(r => setTestMsg(r.success ? ('✓ Email elküldve: ' + testEmail) : ('✗ ' + (r.error || 'Ismeretlen hiba'))));
  };

  const testSms = () => {
    if (!testPhone) return;
    setSmsMsg('Küldés...');
    apiFetch('api/settings.php?action=test_sms', {
      method: 'POST',
      body: JSON.stringify({ test_phone: testPhone })
    }).then(r => setSmsMsg(r.success ? ('✓ SMS elküldve: ' + (r.to || testPhone)) : ('✗ ' + (r.error || 'Hiba'))));
  };

  if (loading) return <LoadingState/>;

  return (
    <div style={{padding: isMobile ? 14 : 24, display:'flex', flexDirection:'column', gap:14}}>

      {/* SMTP */}
      <Card title="Email / SMTP" subtitle="Tárhely.eu SMTP beállítások — mentés után azonnal aktív">
        <div style={{display:'grid', gridTemplateColumns: isMobile ? '1fr' : '1fr 1fr', gap:12, marginBottom:14}}>
          {[
            ['SMTP szerver', 'smtp_host', 'text',     'mail.fodoringatlan.hu'],
            ['Felhasználónév (email)', 'smtp_user', 'text', 'info@fodoringatlan.hu'],
            ['Jelszó', 'smtp_pass', 'password', '••••••••'],
            ['Port', 'smtp_port', 'text', '587'],
            ['Feladó neve', 'smtp_from_name', 'text', 'Fodor Ingatlan'],
            ['Titkosítás', 'smtp_secure', 'text', 'tls / ssl'],
          ].map(([lbl, key, type, ph]) => (
            <div key={key}>
              <div style={{fontSize:11, color:C.mute, marginBottom:4, fontFamily:'"DM Mono", monospace', letterSpacing:0.6}}>{lbl.toUpperCase()}</div>
              <input
                type={type}
                value={smtpForm[key]}
                onChange={e => setSmtpForm(f => ({...f, [key]: e.target.value}))}
                placeholder={ph}
                style={{width:'100%', boxSizing:'border-box', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'"DM Mono", monospace'}}
              />
            </div>
          ))}
        </div>
        <div style={{display:'flex', gap:8, alignItems:'center', flexWrap:'wrap'}}>
          <button onClick={() => saveConfig(smtpForm, setSmtpSaveMsg, setSavingSmtp)} disabled={savingSmtp}
            style={{...pillBtn(true), padding:'8px 14px', opacity: savingSmtp ? 0.6 : 1}}>
            {savingSmtp ? 'Mentés...' : 'Beállítások mentése'}
          </button>
          <div style={{width:1, height:20, background:C.line}}/>
          <input value={testEmail} onChange={e => setTestEmail(e.target.value)}
            placeholder="teszt@email.com"
            style={{padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit', flex:1, minWidth:160}}/>
          <button onClick={testSmtp} style={{...pillBtn(false), padding:'8px 14px'}}>SMTP teszt</button>
          {smtpSaveMsg && <span style={{fontSize:12, color: smtpSaveMsg.startsWith('✓') ? C.ok : C.bad, fontWeight:600}}>{smtpSaveMsg}</span>}
          {testMsg && <span style={{fontSize:12, color: testMsg.startsWith('✓') ? C.ok : C.bad}}>{testMsg}</span>}
        </div>
      </Card>

      {/* Twilio */}
      <Card title="Twilio SMS" subtitle="SMS küldés — console.twilio.com · mentés után azonnal aktív">
        <div style={{display:'grid', gridTemplateColumns: isMobile ? '1fr' : '1fr 1fr', gap:12, marginBottom:14}}>
          {[
            ['Account SID', 'twilio_sid', 'password', 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'],
            ['Auth Token', 'twilio_token', 'password', '••••••••••••••••••••••••••••••••'],
            ['Küldő szám (E.164)', 'twilio_from', 'text', '+1XXXXXXXXXX'],
          ].map(([lbl, key, type, ph]) => (
            <div key={key}>
              <div style={{fontSize:11, color:C.mute, marginBottom:4, fontFamily:'"DM Mono", monospace', letterSpacing:0.6}}>{lbl.toUpperCase()}</div>
              <input
                type={type}
                value={twilioForm[key]}
                onChange={e => setTwilioForm(f => ({...f, [key]: e.target.value}))}
                placeholder={ph}
                autoComplete="new-password"
                style={{width:'100%', boxSizing:'border-box', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'"DM Mono", monospace'}}
              />
            </div>
          ))}
          <div>
            <div style={{fontSize:11, color:C.mute, marginBottom:4, fontFamily:'"DM Mono", monospace', letterSpacing:0.6}}>JELENLEGI ÁLLAPOT</div>
            <div style={{padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:12, background: config?.twilio?.sid ? '#E8F1E5' : C.creamSoft, color: config?.twilio?.sid ? C.ok : C.mute}}>
              {config?.twilio?.sid
                ? `✓ Konfigurálva · SID: ${config.twilio.sid} · Szám: ${config?.twilio?.from || '–'}`
                : '✗ Nincs konfigurálva'}
            </div>
          </div>
        </div>
        <div style={{display:'flex', gap:8, alignItems:'center', flexWrap:'wrap'}}>
          <button onClick={() => saveConfig(twilioForm, setTwilioSaveMsg, setSavingTwilio)} disabled={savingTwilio}
            style={{...pillBtn(true), padding:'8px 14px', opacity: savingTwilio ? 0.6 : 1}}>
            {savingTwilio ? 'Mentés...' : 'Beállítások mentése'}
          </button>
          <div style={{width:1, height:20, background:C.line}}/>
          <input value={testPhone} onChange={e => setTestPhone(e.target.value)}
            placeholder="+36301234567 vagy 06301234567"
            style={{padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit', flex:1, minWidth:200}}/>
          <button onClick={testSms} style={{...pillBtn(false), padding:'8px 14px'}}>Teszt SMS</button>
          {twilioSaveMsg && <span style={{fontSize:12, color: twilioSaveMsg.startsWith('✓') ? C.ok : C.bad, fontWeight:600}}>{twilioSaveMsg}</span>}
          {smsMsg && <span style={{fontSize:12, color: smsMsg.startsWith('✓') ? C.ok : C.bad}}>{smsMsg}</span>}
        </div>
        <div style={{marginTop:10, fontSize:11, color:C.mute, lineHeight:1.6}}>
          Magyar szám: <code style={{fontFamily:'"DM Mono"', background:C.creamSoft, padding:'1px 4px', borderRadius:3}}>06301234567</code> → automatikusan konvertál <code style={{fontFamily:'"DM Mono"', background:C.creamSoft, padding:'1px 4px', borderRadius:3}}>+36301234567</code> formátumra küldéskor.
        </div>
      </Card>

      {/* Google API */}
      <Card title="Google Places API" subtitle="Értékelések szinkronizálása — mentés után azonnal aktív">
        <div style={{
          padding:'10px 14px', borderRadius:8, marginBottom:14,
          background: config?.google?.api_key_set ? '#E8F1E5' : '#FEF2F2',
          color: config?.google?.api_key_set ? C.ok : C.bad,
          fontSize:12.5, fontWeight:600
        }}>
          {config?.google?.api_key_set
            ? `✓ API kulcs beállítva (${config.google.api_key_prefix})`
            : '✗ Nincs beállítva — az alkalmazás demo adatokkal fut'}
        </div>
        <div style={{marginBottom:12}}>
          <div style={{fontSize:11, color:C.mute, marginBottom:4, fontFamily:'"DM Mono", monospace', letterSpacing:0.6}}>GOOGLE PLACES API KULCS</div>
          <input
            type="password"
            value={googleForm.google_api_key}
            onChange={e => setGoogleForm({google_api_key: e.target.value})}
            placeholder="AIzaSy..."
            autoComplete="new-password"
            style={{width:'100%', boxSizing:'border-box', padding:'8px 12px', borderRadius:6, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'"DM Mono", monospace'}}
          />
        </div>
        <div style={{display:'flex', gap:8, alignItems:'center'}}>
          <button onClick={() => saveConfig(googleForm, setGoogleSaveMsg, setSavingGoogle)} disabled={savingGoogle}
            style={{...pillBtn(true), padding:'8px 14px', opacity: savingGoogle ? 0.6 : 1}}>
            {savingGoogle ? 'Mentés...' : 'Beállítások mentése'}
          </button>
          {googleSaveMsg && <span style={{fontSize:12, color: googleSaveMsg.startsWith('✓') ? C.ok : C.bad, fontWeight:600}}>{googleSaveMsg}</span>}
        </div>
      </Card>

      {/* Cron status */}
      <Card title="Cron feladatok" subtitle="Automatikus futtatási ütemezés">
        {cronStatus && Object.keys(cronStatus).length === 0 && (
          <div style={{color:C.mute, fontSize:12}}>Nincs cron adat.</div>
        )}
        {cronStatus && Object.entries(cronStatus).map(([k, v]) => (
          <div key={k} style={{display:'flex', justifyContent:'space-between', padding:'10px 0', borderBottom:`1px solid ${C.lineSoft}`, fontSize:12}}>
            <div>
              <div style={{fontWeight:600, color:C.navyDeep, fontFamily:'"DM Mono", monospace'}}>{k}.php</div>
              <div style={{fontSize:11, color:C.mute, marginTop:2}}>{v.last_output}</div>
            </div>
            <div style={{textAlign:'right', color:C.mute, fontFamily:'"DM Mono", monospace', fontSize:11}}>
              {v.last_run || 'Még nem futott'}
            </div>
          </div>
        ))}
      </Card>

      {/* Logs */}
      <Card title="Rendszernapló" subtitle="Utolsó 20 bejegyzés">
        <div style={{fontFamily:'"DM Mono", monospace', fontSize:11, lineHeight:1.8}}>
          {logs.length === 0 && <div style={{color:C.mute}}>Nincs naplóbejegyzés.</div>}
          {logs.map((l, i) => (
            <div key={i} style={{
              padding:'2px 0',
              borderBottom:`1px solid ${C.lineSoft}`,
              color: l.level === 'error' ? C.bad : l.level === 'warn' ? C.warn : C.mute
            }}>
              <span style={{color:C.mute}}>{l.time || ''}{'  '}</span>
              <span style={{color: l.level === 'error' ? C.bad : C.navy, fontWeight:600}}>
                [{(l.level || 'info').toUpperCase()}]{'  '}
              </span>
              {l.message || l.raw || JSON.stringify(l)}
            </div>
          ))}
        </div>
      </Card>

      {/* Users */}
      <Card title="Felhasználók" subtitle="Bejelentkezési hozzáférések kezelése">
        <div style={{marginBottom:10, display:'flex', justifyContent:'flex-end'}}>
          <button onClick={openNewUser} style={{...pillBtn(true), padding:'7px 12px'}}>+ Új felhasználó</button>
        </div>
        {users.length === 0 && <div style={{color:C.mute, fontSize:12}}>Nincs felhasználó. Futtasd le az <code style={{fontFamily:'"DM Mono"', background:C.creamSoft, padding:'1px 4px', borderRadius:3}}>api/migrate_users.php?key=fodor-migrate-2026</code> scriptet.</div>}
        {users.map(u => (
          <div key={u.id} style={{display:'flex', alignItems:'center', gap:10, padding:'9px 0', borderBottom:`1px solid ${C.lineSoft}`}}>
            <div style={{width:30, height:30, borderRadius:'50%', background:u.active ? `linear-gradient(135deg, ${C.gold}, ${C.goldDeep})` : C.line, display:'flex', alignItems:'center', justifyContent:'center', fontWeight:700, color:C.navyDeep, fontSize:11, flexShrink:0}}>
              {(u.name||'?').split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase()}
            </div>
            <div style={{flex:1, minWidth:0}}>
              <div style={{fontSize:12.5, fontWeight:600, color: u.active ? C.navyDeep : C.mute}}>{u.name}</div>
              <div style={{fontSize:11, color:C.mute, fontFamily:'"DM Mono", monospace'}}>{u.email} · {u.role}</div>
            </div>
            {!u.active && <span style={{fontSize:10, color:C.bad, fontWeight:700, fontFamily:'"DM Mono"'}}>INAKTÍV</span>}
            <button onClick={() => openEditUser(u)} style={{...pillBtn(false), padding:'5px 9px', fontSize:11}}>Szerkeszt</button>
            {u.active && <button onClick={() => deactivateUser(u.id)} style={{...pillBtn(false), padding:'5px 9px', fontSize:11, color:C.bad, borderColor:C.bad}}>Deaktivál</button>}
          </div>
        ))}
      </Card>

      {/* User modal */}
      {userModal !== null && (
        <div style={{position:'fixed', inset:0, background:'rgba(0,0,0,0.4)', display:'flex', alignItems:'center', justifyContent:'center', zIndex:9999}}>
          <div style={{background:C.white, borderRadius:12, padding:'28px 32px', width:380, boxShadow:'0 8px 32px rgba(0,0,0,0.18)'}}>
            <h3 style={{margin:'0 0 18px', fontSize:16, fontFamily:'"DM Serif Display", serif', color:C.navyDeep}}>
              {userModal.id ? 'Felhasználó szerkesztése' : 'Új felhasználó'}
            </h3>
            {[
              ['Név', 'name', 'text', 'Teljes név'],
              ['Email', 'email', 'email', 'pelda@email.hu'],
              ['Jelszó', 'password', 'password', userModal.id ? '(változatlan ha üres)' : 'Min. 8 karakter'],
            ].map(([lbl, key, type, ph]) => (
              <div key={key} style={{marginBottom:12}}>
                <label style={{fontSize:11.5, color:C.mute, fontFamily:'"DM Mono", monospace', letterSpacing:0.8, display:'block', marginBottom:4}}>{lbl.toUpperCase()}</label>
                <input
                  type={type} value={userForm[key]} placeholder={ph}
                  onChange={e => setUserForm(f => ({...f, [key]: e.target.value}))}
                  style={{width:'100%', padding:'8px 11px', borderRadius:7, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit', boxSizing:'border-box'}}
                />
              </div>
            ))}
            <div style={{marginBottom:16}}>
              <label style={{fontSize:11.5, color:C.mute, fontFamily:'"DM Mono", monospace', letterSpacing:0.8, display:'block', marginBottom:4}}>SZEREPKÖR</label>
              <select value={userForm.role} onChange={e => setUserForm(f => ({...f, role: e.target.value}))}
                style={{width:'100%', padding:'8px 11px', borderRadius:7, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit'}}>
                <option value="admin">Admin</option>
                <option value="agent">Ügynök</option>
                <option value="viewer">Megtekintő</option>
              </select>
            </div>
            {userErr && <div style={{fontSize:12, color:C.bad, marginBottom:10, padding:'7px 10px', background:'#FEF2F2', borderRadius:6}}>{userErr}</div>}
            <div style={{display:'flex', gap:8, justifyContent:'flex-end'}}>
              <button onClick={() => setUserModal(null)} style={pillBtn(false)}>Mégse</button>
              <button onClick={saveUser} style={pillBtn(true)}>Mentés</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// =================== LOGIN PAGE ===================
function LoginPage({ onLogin }) {
  const [email, setEmail]       = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading]   = useState(false);
  const [error, setError]       = useState('');

  const handleSubmit = (e) => {
    e.preventDefault();
    if (!email || !password) { setError('Email és jelszó kötelező.'); return; }
    setLoading(true);
    setError('');
    fetch('api/login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password })
    })
    .then(r => r.json())
    .then(d => {
      if (d.token) {
        localStorage.setItem('fodor_token', d.token);
        localStorage.setItem('fodor_user', JSON.stringify(d.user));
        onLogin(d.token, d.user);
      } else {
        setError(d.error || 'Bejelentkezés sikertelen.');
      }
    })
    .catch(() => setError('Hálózati hiba. Próbáld újra.'))
    .finally(() => setLoading(false));
  };

  return (
    <div style={{
      minHeight:'100vh', background:C.navy,
      display:'flex', alignItems:'center', justifyContent:'center',
      fontFamily:'"DM Sans", system-ui, sans-serif'
    }}>
      <div style={{
        background:C.creamSoft, borderRadius:16, padding:'40px 44px',
        width:360, boxShadow:'0 12px 40px rgba(0,0,0,0.25)'
      }}>
        <div style={{marginBottom:28, display:'flex', flexDirection:'column', alignItems:'center'}}>
          <Logo light/>
          <div style={{fontSize:13, color:C.mute, marginTop:6}}>Fodor Értékelő</div>
        </div>
        <form onSubmit={handleSubmit}>
          <div style={{marginBottom:14}}>
            <label style={{fontSize:11.5, color:C.mute, fontFamily:'"DM Mono", monospace', letterSpacing:0.8, display:'block', marginBottom:5}}>EMAIL</label>
            <input
              type="email" value={email} onChange={e => setEmail(e.target.value)}
              autoFocus placeholder="admin@fodoringatlan.hu"
              style={{width:'100%', padding:'10px 12px', borderRadius:8, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit', boxSizing:'border-box'}}
            />
          </div>
          <div style={{marginBottom:20}}>
            <label style={{fontSize:11.5, color:C.mute, fontFamily:'"DM Mono", monospace', letterSpacing:0.8, display:'block', marginBottom:5}}>JELSZÓ</label>
            <input
              type="password" value={password} onChange={e => setPassword(e.target.value)}
              placeholder="••••••••"
              style={{width:'100%', padding:'10px 12px', borderRadius:8, border:`1px solid ${C.line}`, fontSize:13, fontFamily:'inherit', boxSizing:'border-box'}}
            />
          </div>
          {error && (
            <div style={{fontSize:12, color:C.bad, marginBottom:12, padding:'8px 12px', background:'#FEF2F2', borderRadius:6}}>
              {error}
            </div>
          )}
          <button
            type="submit" disabled={loading}
            style={{width:'100%', padding:'11px 0', borderRadius:8, border:'none', background:C.navyDeep, color:C.cream, fontSize:13.5, fontWeight:700, cursor:loading?'not-allowed':'pointer', opacity:loading?0.7:1, fontFamily:'inherit'}}
          >
            {loading ? 'Belépés...' : 'Belépés'}
          </button>
        </form>
      </div>
    </div>
  );
}

// =================== APP ROOT ===================
function App() {
  const [token, setToken]           = useState(localStorage.getItem('fodor_token'));
  const [active, setActive]         = useState('dash');
  const [showNewRequest, setShowNewRequest] = useState(false);
  const [agents, setAgents]             = useState([]);
  const [templates, setTemplates]       = useState([]);
  const [appOffices, setAppOffices]     = useState([]);
  const [appAutos, setAppAutos]         = useState([]);
  const [sidebarOpen, setSidebarOpen]   = useState(false);
  const w = useWindowWidth();
  const isMobile = w < 768;

  useEffect(() => {
    if (!token) return;
    apiFetch('api/agents.php').then(d => setAgents(d.data || d || [])).catch(() => {});
    apiFetch('api/templates.php').then(d => setTemplates(d.data || d || [])).catch(() => {});
    apiFetch('api/offices.php').then(d => setAppOffices(d.data || d || [])).catch(() => {});
    apiFetch('api/automations.php').then(d => setAppAutos(d.automations || d.data || d || [])).catch(() => {});
  }, [token]);

  const handleLogin = (tok) => setToken(tok);

  const handleLogout = () => {
    const tok = localStorage.getItem('fodor_token');
    if (tok) fetch('api/login.php', { method:'DELETE', headers:{ 'Authorization':'Bearer ' + tok } });
    localStorage.removeItem('fodor_token');
    localStorage.removeItem('fodor_user');
    setToken(null);
  };

  if (!token) return <LoginPage onLogin={handleLogin}/>;

  const titles = {
    dash: 'Vezérlőpult',
    profiles: 'Profilok & Ügynökök',
    automations: 'Automatizmusok',
    verify: 'Visszaellenőrzés',
    stats: 'Statisztikák',
    inbox: 'Elküldött kérések',
    templates: 'Üzenetsablonok',
    campaigns: 'Kampányok',
    settings: 'Beállítások',
  };

  const activeAutoCount = appAutos.filter(a => a.active === 1 || a.active === true).length;
  const subtitles = {
    dash: 'Élő áttekintés a Fodor Ingatlan Google-értékelési rendszeréről',
    profiles: `${appOffices.length || '–'} iroda · ${agents.length || '–'} ügynök`,
    automations: `${appAutos.length || '–'} szabály · ${activeAutoCount} aktív`,
    verify: 'A kiküldött kérések publikálásának nyomon követése',
    stats: 'Az utolsó 12 hónap mély elemzése',
    inbox: 'Kiküldött értékelés-kérések és beérkezett értékelések',
    templates: 'Email és SMS sablonok kezelése',
    campaigns: 'Kiküldési kampányok és statisztikák',
    settings: 'Rendszer és integrációs beállítások',
  };

  const crumbs = {
    dash: 'VEZÉRLŐPULT',
    profiles: 'PROFILOK',
    automations: 'AUTOMATIZMUS',
    verify: 'VISSZAELLENŐRZÉS',
    stats: 'STATISZTIKA',
    inbox: 'ELKÜLDÖTT',
    templates: 'SABLONOK',
    campaigns: 'KAMPÁNYOK',
    settings: 'BEÁLLÍTÁS',
  };

  return (
    <div style={{
      display:'flex', minHeight:'100vh',
      background:C.bg,
      fontFamily:'"DM Sans", system-ui, sans-serif',
      color:C.navy
    }}>
      {isMobile && sidebarOpen && (
        <div
          onClick={() => setSidebarOpen(false)}
          style={{position:'fixed', inset:0, background:'rgba(0,0,0,0.45)', zIndex:499}}
        />
      )}
      <Sidebar
        active={active}
        setActive={setActive}
        onLogout={handleLogout}
        isOpen={!isMobile || sidebarOpen}
        onClose={() => setSidebarOpen(false)}
      />
      <main style={{flex:1, minWidth:0, display:'flex', flexDirection:'column', overflowX:'hidden'}}>
        <TopBar
          title={titles[active] || active}
          subtitle={subtitles[active] || ''}
          crumbs={['FODOR ÉRTÉKELŐ', crumbs[active] || active.toUpperCase()]}
          onNewRequest={() => setShowNewRequest(true)}
          onFilter={() => setShowNewRequest(true)}
          onBell={() => setActive('inbox')}
          onMenuOpen={() => setSidebarOpen(true)}
          isMobile={isMobile}
        />
        {active === 'dash' && <Dashboard onNavigate={setActive}/>}
        {active === 'profiles' && <ProfilesView onNavigate={setActive}/>}
        {active === 'automations' && <AutomationsView/>}
        {active === 'verify' && <VerifyView/>}
        {active === 'stats' && <StatsView/>}
        {active === 'inbox' && <InboxView/>}
        {active === 'templates' && <TemplatesView/>}
        {active === 'campaigns' && <CampaignsView/>}
        {active === 'settings' && <SettingsView/>}
      </main>
      {showNewRequest && (
        <NewRequestModal
          onClose={() => setShowNewRequest(false)}
          agents={agents}
          automations={appAutos}
        />
      )}
    </div>
  );
}

class ErrorBoundary extends React.Component {
  constructor(props) { super(props); this.state = { error: null }; }
  static getDerivedStateFromError(e) { return { error: e }; }
  render() {
    if (this.state.error) {
      return (
        <div style={{padding:40, fontFamily:'monospace', background:'#fff0f0', color:'#900', minHeight:'100vh'}}>
          <h2>React hiba — másold ki és küldd el</h2>
          <pre style={{whiteSpace:'pre-wrap', wordBreak:'break-all'}}>{String(this.state.error)}</pre>
          <pre style={{whiteSpace:'pre-wrap', wordBreak:'break-all', fontSize:11, color:'#555'}}>{this.state.error?.stack}</pre>
        </div>
      );
    }
    return this.props.children;
  }
}

ReactDOM.createRoot(document.getElementById('root')).render(<ErrorBoundary><App/></ErrorBoundary>);
