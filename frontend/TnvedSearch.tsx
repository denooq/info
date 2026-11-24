import {useState} from 'react';
import type {Entity,FieldChange} from './types';

type Result={code:string;name:string;fullName:string;sourceUrl:string;page:number};
export function TnvedSearch({row,busy,onSelect}:{row:Entity;busy:boolean;onSelect:(change:FieldChange)=>Promise<void>}) {
  const [query,setQuery]=useState(String(row.description??''));const [results,setResults]=useState<Result[]>([]);const [error,setError]=useState('');const [loading,setLoading]=useState(false);
  async function search(event?:{preventDefault():void}) {
    event?.preventDefault();if(query.trim().length<2){setError('Введите минимум два символа кода или наименования.');setResults([]);return;}
    setLoading(true);setError('');try{const response=await fetch(`/api/classifiers/tnved?q=${encodeURIComponent(query.trim())}&limit=12`);const data=await response.json();if(!response.ok)throw new Error(data.message??'Не удалось выполнить поиск по справочнику.');setResults(data.items??[]);if(!(data.items??[]).length)setError('В справочнике нет подходящих позиций.');}
    catch(cause){setError((cause as Error).message);}finally{setLoading(false);}
  }
  async function select(code:string) {
    setError('');try{await onSelect({fieldId:'goods.commodityCode',entityId:String(row.id),value:code});setResults([]);}catch(cause){setError((cause as Error).message||'Не удалось сохранить код товара.');}
  }
  return <section className="tnved-search" aria-label="Поиск по справочнику ТН ВЭД"><form onSubmit={search}><label htmlFor={`tnved-search-${row.id}`}>Найти код или наименование в справочнике</label><div className="tnved-search-controls"><input id={`tnved-search-${row.id}`} value={query} disabled={busy||loading} onChange={event=>setQuery(event.target.value)} onKeyDown={event=>{if(event.key==='Escape')setResults([]);}}/><button className="secondary" type="submit" disabled={busy||loading}>{loading?'Поиск…':'Найти'}</button></div></form>{error&&<p className="candidate-warning" role="status">{error}</p>}{results.length>0&&<ul className="tnved-results" aria-label="Результаты справочника">{results.map(result=><li key={result.code}><button type="button" className="tnved-result" disabled={busy} onClick={()=>void select(result.code)}><strong>{result.code}</strong><span>{result.fullName||result.name}</span></button><a href={result.sourceUrl} target="_blank" rel="noopener noreferrer">Источник, стр. {result.page}</a></li>)}</ul>}</section>;
}
