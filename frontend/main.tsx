import { useCallback, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { ApiError, request } from './api';
import { UploadPage } from './UploadPage';
import { ReviewPage } from './ReviewPage';
import { CalculationPage } from './CalculationPage';
import type { Session, Registry, FieldChange, UploadFailure } from './types';
import {rateRequestKey,ratesComplete} from './ExchangeRates';
import {recognitionKey,recognitionProgress} from './recognition';
import './style.css';

function App() {
  const [session,setSession]=useState<Session|null>(null); const current=useRef<Session|null>(null);
  const [ratesLoading,setRatesLoading]=useState(false);const [ratesError,setRatesError]=useState('');const lastRateRequest=useRef<string|null>(null);
  const [busy,setBusy]=useState(false); const blocking=useRef(0); const tail=useRef<Promise<unknown>>(Promise.resolve());
  const [errors,setErrors]=useState<string[]>([]); const [fatal,setFatal]=useState<string|null>(null);
  const [uploadFailures,setUploadFailures]=useState<UploadFailure[]>([]);
  const [connection,setConnection]=useState<{code:string;message:string}|null>(null);
  const [registry,setRegistry]=useState<Registry|null>(null); const [page,setPage]=useState<'upload'|'review'|'calculation'|null>(null);
  const [awaitingRecognition,setAwaitingRecognition]=useState(false);const recognitionTarget=useRef<string|null>(null);
  const update=useCallback((value:Session)=>{current.current=value;setSession(value);},[]);
  function startBlocking(){blocking.current++;setBusy(true);}
  function stopBlocking(){blocking.current--;setBusy(blocking.current>0);}
  function connectionError(error:unknown) {
    if(error instanceof ApiError && ['SESSION_REQUIRED','SESSION_EXPIRED'].includes(error.code)) setConnection({code:error.code,message:error.message});
    else if(!(error instanceof ApiError)) setConnection({code:'CONNECTION_ERROR',message:'Не удалось связаться с сервером. Введённые данные остаются на странице.'});
  }
  function mutate(operation:(active:Session)=>Promise<Session>,blockUI=true):Promise<void> {
    if(blockUI)startBlocking();
    const task=tail.current.then(async()=>{
      const active=current.current;
      if(!active)throw new Error('Текущая сессия ещё не загружена.');
      try {const next=await operation(active);update(next);setConnection(null);}
      catch(error){connectionError(error);throw error;}
    });
    const settled=task.finally(()=>{if(blockUI)stopBlocking();});
    tail.current=settled.then(()=>undefined,()=>undefined);
    return settled;
  }
  const initialize=useCallback(async()=>{
    setFatal(null);
    try {update(await request<Session>('/api/session'));setConnection(null);}
    catch(error) {
      if(current.current){connectionError(error);return;}
      if(error instanceof ApiError && [401,410].includes(error.status)) {
        try {update(await request<Session>('/api/session',{method:'POST'}));}
        catch(createError){setFatal((createError as Error).message);}
      } else setFatal((error as Error).message);
    }
  },[update]);
  useEffect(()=>{void initialize();},[initialize]);
  useEffect(()=>{const open=()=>setPage('review');window.addEventListener('customs-open-review',open);return()=>window.removeEventListener('customs-open-review',open);},[]);
  useEffect(()=>{void request<Registry>('/api/registry').then(setRegistry).catch(e=>setFatal(e.message));},[]);
  useEffect(()=>{
    if(!session)return;
    const key=recognitionKey(session);const progress=recognitionProgress(session);
    if(progress.active){recognitionTarget.current??=key;setAwaitingRecognition(true);}
    else if(recognitionTarget.current!==null) {
      if(recognitionTarget.current!==key){recognitionTarget.current=null;setAwaitingRecognition(false);}
      else if(progress.terminal){recognitionTarget.current=null;setAwaitingRecognition(false);setPage('review');}
    }
  },[session]);
  useEffect(()=>{
    if(!session || session.phase!=='recognizing')return;
    let mounted=true;let inFlight=false;const controller=new AbortController();
    const timer=window.setInterval(()=>{
      if(inFlight||!current.current)return;inFlight=true;
      const snapshot=current.current;const key=recognitionKey(snapshot);
      void request<Session>('/api/session',{signal:controller.signal}).then(value=>{
        // Progress does not bump dataRevision: also check session, file set and effect lifetime.
        if(mounted&&current.current?.dataRevision===snapshot.dataRevision&&recognitionKey(current.current)===key&&recognitionKey(value)===key&&value.dataRevision>=snapshot.dataRevision){update(value);setConnection(null);}
      }).catch(error=>{if(mounted)connectionError(error);}).finally(()=>{inFlight=false;});
    },2500);
    return ()=>{mounted=false;controller.abort();window.clearInterval(timer);};
  },[session?.phase,session?.jobs,update]);
  async function refreshRates(force=false) {
    setRatesLoading(true);setRatesError('');
    try {await mutate(active=>request<Session>('/api/rates',{method:'POST',body:JSON.stringify({revision:active.dataRevision,force})},active.csrfToken),false);}
    catch(error){setRatesError((error as Error).message);}finally{setRatesLoading(false);}
  }
  const rateKey=session?.capabilities?.nbkRates && ['reviewing','calculation','calculated'].includes(session.phase)?rateRequestKey(session):null;
  useEffect(()=>{
    if(!rateKey || !session || ratesLoading || lastRateRequest.current===rateKey)return;
    lastRateRequest.current=rateKey;
    if(!ratesComplete(session))void refreshRates();
  },[rateKey,ratesLoading]);
  async function reconnect() {
    setErrors([]);
    try {await mutate(()=>request<Session>('/api/session'));} catch { /* Keep the current form and its unsaved inputs. */ }
  }
  async function newSession() {
    startBlocking();
    try {await tail.current;recognitionTarget.current=null;setAwaitingRecognition(false);update(await request<Session>('/api/session',{method:'POST'}));setConnection(null);setErrors([]);setUploadFailures([]);setPage('upload');}
    catch(error){setErrors([(error as Error).message]);}finally{stopBlocking();}
  }
  async function upload(files:File[],groupId:string) {
    if(!current.current || busy || files.length===0)return;
    startBlocking();const failures:UploadFailure[]=[];setErrors([]);
    setUploadFailures(previous=>previous.filter(f=>f.groupId!==groupId||!files.some(file=>file.name===f.name)));
    try {
      for(const file of files)try {
        await mutate(active=>{const body=new FormData();body.set('revision',String(active.dataRevision));body.set('groupId',groupId);body.set('file',file);return request<Session>('/api/documents',{method:'POST',body},active.csrfToken);},false);
      }catch(error){failures.push({groupId,name:file.name,message:(error as Error).message});}
      setUploadFailures(previous=>[...previous,...failures]);
    }finally{stopBlocking();}
  }
  async function remove(id:string) {
    setErrors([]);
    try {await mutate(active=>request<Session>(`/api/documents/${id}`,{method:'DELETE',body:JSON.stringify({revision:active.dataRevision})},active.csrfToken));}
    catch(error){setErrors([(error as Error).message]);}
  }
  async function group(body:Record<string,unknown>) {
    setErrors([]);
    try{await mutate(active=>request<Session>('/api/upload-groups',{method:'POST',body:JSON.stringify({...body,revision:active.dataRevision})},active.csrfToken));if(body.action==='remove')setUploadFailures(previous=>previous.filter(f=>f.groupId!==body.id));}
    catch(error){setErrors([(error as Error).message]);throw error;}
  }
  async function assign(fileId:string,groupId:string) {
    setErrors([]);
    try{await mutate(active=>request<Session>(`/api/documents/${fileId}/type`,{method:'PATCH',body:JSON.stringify({revision:active.dataRevision,groupId})},active.csrfToken));}
    catch(error){setErrors([(error as Error).message]);}
  }
  async function recognize() {
    if(blocking.current>0||!current.current||recognitionProgress(current.current).active)return;
    setErrors([]);setAwaitingRecognition(true);recognitionTarget.current=recognitionKey(current.current);
    try {await mutate(active=>request<Session>('/api/recognize',{method:'POST',body:JSON.stringify({retry:true})},active.csrfToken));}
    catch(error){recognitionTarget.current=null;setAwaitingRecognition(false);setErrors([(error as Error).message]);}
  }
  async function change(change:FieldChange) {
    setErrors([]);
    // Blur saves are queued without disabling the button the user is clicking.
    await mutate(active=>change.requiredDocumentKind||change.recordKind
      ?request<Session>('/api/entities',{method:'POST',body:JSON.stringify({revision:active.dataRevision,scope:'document',action:change.recordKind?'record':'required',kind:change.recordKind??change.requiredDocumentKind,...(change.recordId?{recordId:change.recordId}:{}),...(change.recordFileId?{fileId:change.recordFileId}:{}),fieldId:change.fieldId,value:change.value})},active.csrfToken)
      :request<Session>('/api/draft',{method:'PATCH',body:JSON.stringify({revision:active.dataRevision,changes:[change]})},active.csrfToken),false);
  }
  async function entity(body:Record<string,unknown>) {
    setErrors([]);
    await mutate(active=>request<Session>('/api/entities',{method:'POST',body:JSON.stringify({...body,revision:active.dataRevision})},active.csrfToken));
  }
  async function calculate() {
    setErrors([]);
    try {await mutate(active=>request<Session>('/api/calculate',{method:'POST',body:JSON.stringify({revision:active.dataRevision})},active.csrfToken));setPage('calculation');}
    catch(error){setErrors([(error as Error).message]);}
  }
  async function validate() {
    setErrors([]);
    try {await mutate(()=>request<Session>('/api/session'));}
    catch(error){setErrors([(error as Error).message]);}
  }
  async function manualRate(currency:string,value:string) {
    if(!session)return;
    await mutate(active=>request<Session>('/api/rates/manual',{method:'POST',body:JSON.stringify({revision:active.dataRevision,currency,value})},active.csrfToken),false);
  }
  async function propose(id:string,key:string,value:string|null) {
    await mutate(active=>request<Session>('/api/calculation',{method:'PATCH',body:JSON.stringify({revision:active.dataRevision,entityId:id,key,value})},active.csrfToken));
  }
  async function enterCalculation() {
    setErrors([]);
    try{await mutate(active=>request<Session>('/api/review/complete',{method:'POST',body:JSON.stringify({revision:active.dataRevision})},active.csrfToken));setPage('calculation');}
    catch(error){setErrors([(error as Error).message]);}
  }
  const step=page==='upload'?1:page==='review'?2:page==='calculation'?3:['calculation','calculated'].includes(session?.phase??'')?3:session?.phase==='reviewing'?2:1;
  return <main><header className="app-header"><span className="brand">Декларация ИМ 40</span><nav aria-label="Этапы подготовки">{['Документы','Проверка','Расчёт'].map((label,index)=><span key={label} className={index+1===step?'active':''} aria-current={index+1===step?'step':undefined}>{index+1}. {label}</span>)}</nav></header>
    {connection && <div className="notice error session-connection" role="alert"><p>{connection.message}</p><p>{connection.code==='SESSION_EXPIRED'?'Серверные данные этой временной сессии больше недоступны. Новая сессия начнётся только по вашей кнопке.':'Откройте приложение по прежнему адресу и проверьте, что cookies разрешены. Проверка соединения не запускает повторное распознавание.'}</p><button className="secondary" disabled={busy} onClick={()=>void reconnect()}>Проверить сессию</button>{connection.code==='SESSION_EXPIRED' && <button className="text-button" disabled={busy} onClick={()=>void newSession()}>Начать новую сессию</button>}</div>}
    {fatal?<div className="notice error" role="alert"><p>{fatal}</p><button className="primary" onClick={()=>{void initialize();void request<Registry>('/api/registry').then(setRegistry).catch(e=>setFatal(e.message));}}>Повторить подключение</button></div>
      :!session||!registry?<p className="loading" role="status">Подготавливаем временную сессию…</p>
      :page==='calculation'||(page===null&&['calculation','calculated'].includes(session.phase))?<CalculationPage session={session} registry={registry} busy={busy} errors={errors} onChange={change} onEntity={entity} onRecalculate={()=>void calculate()} onPropose={propose} onBack={()=>setPage('review')} onRates={()=>void refreshRates(true)} ratesLoading={ratesLoading} ratesError={ratesError}/>
      :page!=='upload'&&(session.phase==='reviewing'||page==='review')?<ReviewPage session={session} registry={registry} busy={busy} onChange={change} onEntity={entity} onBack={()=>setPage('upload')} onCalculate={()=>void enterCalculation()} onValidate={()=>void validate()} onRates={()=>void refreshRates(true)} onManualRate={manualRate} ratesLoading={ratesLoading} ratesError={ratesError} errors={errors}/>
      :<><UploadPage session={session} busy={busy} recognizing={awaitingRecognition} errors={errors} uploadFailures={uploadFailures} onUpload={(files,groupId)=>void upload(files,groupId)} onGroup={group} onAssign={assign} onRemove={id=>void remove(id)} onRecognize={()=>void recognize()}/>{!awaitingRecognition&&['reviewing','calculated','calculation'].includes(session.phase)&&<button className="primary" onClick={()=>setPage('review')}>К проверке данных</button>}</>}
  </main>;
}

createRoot(document.getElementById('root')!).render(<App />);
