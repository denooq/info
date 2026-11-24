import {useEffect,useRef,useState} from 'react';
import {domId} from './FieldRow';
import {SourcePopover} from './SourcePopover';
import {pendingInput,rememberInput,forgetInput} from './inputCache';
import type {CompactGraphValue,FieldChange,Graph,Session} from './types';

export function GraphTextEditor({item,graph,session,busy,hint,onChange}:{item:CompactGraphValue;graph:Graph;session:Session;busy:boolean;hint?:string;onChange:(c:FieldChange)=>Promise<void>}) {
  const remote=item.value??'';const cached=pendingInput(session.csrfToken,item.address);const [local,setLocal]=useState(cached??remote);const localRef=useRef(cached??remote);const dirty=useRef(cached!==undefined);const [saving,setSaving]=useState(false);const [error,setError]=useState('');
  useEffect(()=>{if(!dirty.current){setLocal(remote);localRef.current=remote;}},[remote,saving]);
  function change(value:string){dirty.current=true;localRef.current=value;setLocal(value);rememberInput(session.csrfToken,item.address,value);}
  async function save(candidateId?:string,chosen?:string){
    const sent=chosen??localRef.current;setError('');setSaving(true);
    if(chosen!==undefined)change(chosen);
    try{await onChange({fieldId:'graph.text',graphId:item.graphId,entityId:item.entityId,value:sent,...(candidateId?{candidateId}:{})});if(localRef.current===sent){dirty.current=false;forgetInput(session.csrfToken,item.address);}}
    catch(e){setError((e as Error).message);}finally{setSaving(false);}
  }
  const issues=[...new Set(session.validation.errors.filter(e=>e.address===item.address).map(e=>e.message))];const message=error||item.error||issues.join(' ');
  const evidence=item.evidenceIds.map(id=>session.evidence[id]).filter(Boolean);
  return <div className={`graph-text-editor ${message?'has-error':''}`} data-address={item.address}>
    <textarea id={domId(item.address)} aria-labelledby={`graph-title-${graph.id}`} aria-invalid={!!message} value={local} disabled={busy||saving} placeholder="не удалось заполнить автоматически" onChange={e=>change(e.target.value)} onBlur={()=>{if(localRef.current!==remote||item.status==='conflict')void save();}}/>
    <div className="compact-meta"><span>{item.status==='manual'?'Введено вручную':item.status==='selected'?'Выбран источник':remote?'Из документов':'Не заполнено'}</span>{evidence.length>0&&<SourcePopover label="Источник">{evidence.map((e,i)=><span key={i}>{e.documentName}{e.page?`, стр. ${e.page}`:''}{e.sheet?`, ${e.sheet}, ${e.cell}`:''}<br/>{e.quote}</span>)}</SourcePopover>}{hint&&<SourcePopover label="Формат">{hint}</SourcePopover>}</div>
    <div className="compact-error" role={message?'alert':undefined}>{message&&<span>{message}</span>}</div>
    <div className="compact-alternatives">{item.choices.length>1&&item.choices.map(choice=><SourcePopover key={choice.id} label={choice.label??choice.value} className={`candidate ${remote===choice.value?'selected':''}`} disabled={busy||saving} onSelect={()=>void save(choice.id,choice.value)}>{choice.evidenceIds.length===0?'Введено вручную':choice.evidenceIds.map(id=>session.evidence[id]).filter(Boolean).map((e,i)=><span key={i}>{e.documentName}{e.page?`, стр. ${e.page}`:e.sheet?`, ${e.sheet}${e.cell?`, ${e.cell}`:''}`:''}<br/>{e.quote}</span>)}</SourcePopover>)}</div>
  </div>;
}
