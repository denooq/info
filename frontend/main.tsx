import { useCallback, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { ApiError, request } from './api';
import { UploadPage } from './UploadPage';
import { ReviewPage } from './ReviewPage';
import { CalculationPage } from './CalculationPage';
import { ResultPage } from './ResultPage';
import type { Session, Registry, FieldChange } from './types';
import './style.css';

function App() {
  const [session, setSession] = useState<Session | null>(null);
  const current = useRef<Session | null>(null);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<string[]>([]);
  const [fatal, setFatal] = useState<string | null>(null);
  const [registry, setRegistry] = useState<Registry | null>(null);
  const [page, setPage] = useState<'upload'|'review'|'calculation'|null>(null);
  const update = useCallback((value: Session) => { current.current = value; setSession(value); }, []);
  const initialize = useCallback(async () => {
    setFatal(null);
    try { update(await request<Session>('/api/session')); }
    catch (error) {
      if (error instanceof ApiError && [401,410].includes(error.status)) {
        try { update(await request<Session>('/api/session', { method: 'POST' })); } catch (createError) { setFatal((createError as Error).message); }
      } else setFatal((error as Error).message);
    }
  }, [update]);
  useEffect(() => { void initialize(); }, [initialize]);
  useEffect(() => { void request<Registry>('/api/registry').then(setRegistry).catch(e=>setFatal(e.message)); }, []);
  useEffect(() => { if(session?.phase==='reviewing')setPage(p=>p==='calculation'?p:null); },[session?.phase]);
  useEffect(() => {
    if (!session || !['recognizing','generating'].includes(session.phase) || session.error) return;
    const timer = window.setInterval(() => { void request<Session>('/api/session').then(update).catch(error => setFatal(error.message)); }, 2500);
    return () => window.clearInterval(timer);
  }, [session?.phase, session?.error, update]);
  async function upload(files: File[]) {
    if (!current.current || busy || files.length === 0) return;
    setBusy(true); const failures: string[] = []; setErrors([]);
    for (const file of files) {
      const active = current.current!; const body = new FormData(); body.set('revision', String(active.dataRevision)); body.set('file', file);
      try { update(await request<Session>('/api/documents', { method: 'POST', body }, active.csrfToken)); }
      catch (error) { failures.push(`${file.name}: ${(error as Error).message}`); }
    }
    setErrors(failures); setBusy(false);
  }
  async function remove(id: string) {
    const active = current.current; if (!active || busy) return;
    setBusy(true); setErrors([]);
    try { update(await request<Session>(`/api/documents/${id}`, { method: 'DELETE', body: JSON.stringify({ revision: active.dataRevision }) }, active.csrfToken)); }
    catch (error) { setErrors([(error as Error).message]); } finally { setBusy(false); }
  }
  async function recognize() {
    const active = current.current; if (!active) return;
    setBusy(true); setErrors([]);
    try { update(await request<Session>('/api/recognize', { method: 'POST', body: JSON.stringify({ retry: true }) }, active.csrfToken)); }
    catch (error) { setErrors([(error as Error).message]); } finally { setBusy(false); }
  }
  async function change(change:FieldChange) {
    const active=current.current; if(!active)return;
    setBusy(true);setErrors([]);
    try { update(await request<Session>('/api/draft',{method:'PATCH',body:JSON.stringify({revision:active.dataRevision,changes:[change]})},active.csrfToken)); }
    finally {setBusy(false);}
  }
  async function entity(body:Record<string,unknown>) {
    const active=current.current; if(!active)return;
    setBusy(true);setErrors([]);
    try {update(await request<Session>('/api/entities',{method:'POST',body:JSON.stringify({...body,revision:active.dataRevision})},active.csrfToken));}
    finally {setBusy(false);}
  }
  async function calculate() {
    const active=current.current; if(!active)return;
    setBusy(true);setErrors([]);
    try {update(await request<Session>('/api/calculate',{method:'POST',body:JSON.stringify({revision:active.dataRevision})},active.csrfToken));setPage('calculation');}
    catch(e){setErrors([(e as Error).message]);}finally{setBusy(false);}
  }
  async function propose(id:string,key:string,value:string|null) {
    const active=current.current;if(!active)return;
    setBusy(true);
    try{update(await request<Session>('/api/calculation',{method:'PATCH',body:JSON.stringify({revision:active.dataRevision,entityId:id,key,value})},active.csrfToken));}finally{setBusy(false);}
  }
  async function create() {
    const active=current.current;if(!active)return;setBusy(true);setErrors([]);
    try{update(await request<Session>('/api/confirm',{method:'POST',body:JSON.stringify({revision:active.dataRevision,calculationHash:(active.calculation as {hash:string}).hash})},active.csrfToken));setPage(null);}catch(e){setErrors([(e as Error).message]);}finally{setBusy(false);}
  }
  const step = session?.phase === 'reviewing' ? 2 : session?.phase === 'calculated' ? 3 : ['ready','generating'].includes(session?.phase??'') ? 4 : 1;
  return <main><header className="app-header"><span className="brand">Декларация ИМ 40</span><nav aria-label="Этапы подготовки">{['Документы','Проверка','Расчёт','Результат'].map((label,index) => <span key={label} className={index + 1 === step ? 'active' : ''} aria-current={index + 1 === step ? 'step' : undefined}>{index + 1}. {label}</span>)}</nav></header>{fatal ? <div className="notice error" role="alert"><p>{fatal}</p><button className="primary" onClick={() => void initialize()}>Начать новую сессию</button></div> : !session || !registry ? <p className="loading" role="status">Подготавливаем временную сессию…</p> : page===null && ['ready','generating'].includes(session.phase) ? <ResultPage session={session} busy={busy} onBack={()=>setPage('review')} onRetry={()=>void create()}/> : page==='calculation' || (page===null && session.phase==='calculated') ? <CalculationPage session={session} registry={registry} busy={busy} errors={errors} onChange={change} onRecalculate={()=>void calculate()} onPropose={propose} onBack={()=>setPage('review')} onCreate={()=>void create()}/> : page!=='upload' && (session.phase==='reviewing' || page==='review') ? <ReviewPage session={session} registry={registry} busy={busy} onChange={change} onEntity={entity} onBack={()=>setPage('upload')} onCalculate={()=>void calculate()} errors={errors}/> : <><UploadPage session={session} busy={busy} errors={errors} onUpload={files => void upload(files)} onRemove={id => void remove(id)} onRecognize={() => void recognize()} />{session.phase==='reviewing' && <button className="primary" onClick={()=>setPage('review')}>К проверке данных</button>}</>}</main>;
}

createRoot(document.getElementById('root')!).render(<App />);
