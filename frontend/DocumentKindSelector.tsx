import {useEffect,useId,useRef,useState} from 'react';
import {request} from './api';

export type DocumentKind={code:string;name:string;selectable:boolean;unavailableReason:string|null;version:string};
type Page={version:string;items:Omit<DocumentKind,'version'>[];total:number;nextOffset:number|null;retired:{code:string;reason:string}[]};
export function DocumentKindSelector({busy,onAdd,label='Вид документа',actionLabel='+ Добавить',addOnSelect=false}:{busy:boolean;onAdd:(kind:DocumentKind)=>Promise<void>;label?:string;actionLabel?:string;addOnSelect?:boolean}) {
  const id=useId();const [query,setQuery]=useState('');const [result,setResult]=useState<Page|null>(null);const [selected,setSelected]=useState<DocumentKind|null>(null);const [open,setOpen]=useState(false);const [active,setActive]=useState(0);const [loading,setLoading]=useState(false);const [error,setError]=useState('');
  const currentQuery=useRef(query);currentQuery.current=query;
  const adding=useRef(false);const [saving,setSaving]=useState(false);
  async function add(kind:DocumentKind) {
    if(busy||adding.current)return;
    adding.current=true;setSaving(true);setError('');
    try {await onAdd(kind);setSelected(null);setQuery('');setOpen(false);}
    catch(e){setError((e as Error).message);}
    finally{adding.current=false;setSaving(false);}
  }
  useEffect(()=>{
    if(!open)return;const controller=new AbortController();
    const timer=setTimeout(()=>{setLoading(true);setError('');void request<Page>(`/api/classifiers/documents?q=${encodeURIComponent(query)}&limit=40`,{signal:controller.signal}).then(page=>{setResult(page);setActive(0);}).catch(e=>{if(!controller.signal.aborted)setError(e.message);}).finally(()=>{if(!controller.signal.aborted)setLoading(false);});},180);
    return()=>{clearTimeout(timer);controller.abort();};
  },[query,open]);
  function choose(index:number){const kind=result?.items[index];if(!kind?.selectable||busy||adding.current)return;const choice={...kind,version:result!.version};setSelected(choice);setQuery(kind.code);setOpen(false);if(addOnSelect)void add(choice);}
  async function more(){if(result?.nextOffset==null)return;setLoading(true);try{const page=await request<Page>(`/api/classifiers/documents?q=${encodeURIComponent(query)}&offset=${result.nextOffset}&limit=40`);if(currentQuery.current!==query)return;setResult({...page,items:[...result.items,...page.items]});}catch(e){if(currentQuery.current===query)setError((e as Error).message);}finally{if(currentQuery.current===query)setLoading(false);}}
  return <div className="document-selector"><label htmlFor={id}>{label}</label><div className="selector-controls"><input id={id} role="combobox" aria-autocomplete="list" aria-expanded={open} aria-controls={`${id}-options`} aria-activedescendant={open&&result?.items[active]?`${id}-option-${active}`:undefined} autoComplete="off" placeholder="Поиск по официальному наименованию или коду" value={query} disabled={busy||saving} onFocus={()=>setOpen(true)} onChange={e=>{setQuery(e.target.value);setResult(null);setSelected(null);setError('');setOpen(true);}} onKeyDown={e=>{if(e.key==='Escape')setOpen(false);if(e.key==='ArrowDown'){e.preventDefault();setOpen(true);setActive(i=>Math.min(i+1,(result?.items.length??1)-1));}if(e.key==='ArrowUp'){e.preventDefault();setActive(i=>Math.max(0,i-1));}if(e.key==='Enter'&&open){e.preventDefault();choose(active);}}}/>{!addOnSelect&&<button type="button" className="primary" disabled={busy||saving||!selected} onClick={()=>void add(selected!)}>{actionLabel}</button>}</div>
    {saving&&<p className="selected-kind" role="status">Сохраняем вид документа…</p>}
    {selected&&<p className="selected-kind">{selected.code} — {selected.name}</p>}
    {open&&<div className="classifier-results"><div role="listbox" id={`${id}-options`} aria-label="Официальные виды документов">{result?.items.map((kind,i)=><button id={`${id}-option-${i}`} role="option" aria-selected={selected?.code===kind.code} aria-disabled={!kind.selectable} className={i===active?'is-active':''} type="button" key={kind.code} onClick={()=>choose(i)}><strong>{kind.code}</strong><span>{kind.name}{!kind.selectable&&<small>{kind.unavailableReason}</small>}</span></button>)}</div>{loading&&<p role="status">Поиск…</p>}{!loading&&result?.total===0&&<p>Совпадений нет.</p>}{result?.retired.map(r=><p key={r.code}>{r.code}: {r.reason}</p>)}{result?.nextOffset!=null&&<button className="text-button" disabled={loading} onClick={()=>void more()}>Показать ещё ({result.items.length} из {result.total})</button>}<button className="text-button" onClick={()=>setOpen(false)}>Закрыть список</button></div>}
    {error&&<div className="field-error" role="alert">{error}{addOnSelect&&selected&&<button type="button" className="text-button" disabled={busy||saving} onClick={()=>void add(selected)}>Повторить добавление</button>}</div>}
  </div>;
}
