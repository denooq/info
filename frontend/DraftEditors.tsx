import {useState} from 'react';
import {domId} from './FieldRow';
import type {Entity,Session} from './types';
export function PackageEditor({row,busy,onSave,errors}:{row:Entity;busy:boolean;onSave:(b:Record<string,unknown>)=>Promise<void>;errors:string[]}) {
  const [label,setLabel]=useState(String(row.label||'')); const [quantity,setQuantity]=useState(String(row.quantity||''));
  return <div className="package-editor"><div className="package-row"><input disabled={busy} aria-label="Название группы мест" placeholder="Например, паллеты из CMR" value={label} onChange={e=>setLabel(e.target.value)}/><input id={domId(`package/${row.id}/quantity`)} disabled={busy} aria-invalid={errors.length>0} aria-label="Количество мест в группе" placeholder="Количество" value={quantity} onChange={e=>setQuantity(e.target.value)} inputMode="numeric"/><button className="secondary" disabled={busy} onClick={()=>void onSave({scope:'package',action:'update',id:row.id,label,quantity:Number(quantity)})}>Сохранить</button><button className="text-button" disabled={busy} onClick={()=>void onSave({scope:'package',action:'remove',id:row.id})}>Удалить</button></div>{errors.length>0 && <p className="field-error">{[...new Set(errors)].join(' ')}</p>}</div>;
}
export function SplitEditor({row,session,busy,onSave}:{row:Entity;session:Session;busy:boolean;onSave:(b:Record<string,unknown>)=>Promise<void>}) {
  const [lines,setLines]=useState<string[]>([]); const ids=row.sourceLineIds as string[];
  return <details className="split-editor"><summary>Разделить исходные строки на товары</summary><p className="muted">Выберите строки для нового товара. Массы и стоимости обеих групп нужно будет заполнить и подтвердить заново.</p>{ids.map(id=><label className="reference-line" key={id}><input type="checkbox" checked={lines.includes(id)} onChange={e=>setLines(e.target.checked?[...lines,id]:lines.filter(l=>l!==id))}/>{session.draft.sourceLines[id]?.quote || id}</label>)}<button className="secondary" disabled={busy || lines.length===0 || lines.length===ids.length} onClick={()=>void onSave({scope:'goods',action:'split',id:row.id,sourceLineIds:lines})}>Отделить выбранные строки</button></details>;
}
export function AllocationEditor({row,session,busy,onSave}:{row:Entity;session:Session;busy:boolean;onSave:(b:Record<string,unknown>)=>Promise<void>}) {
  const [values,setValues]=useState<Record<string,string>>((row.allocations as Record<string,string>)||{});
  return <div className="allocation-editor"><h4>Ручные доли в валюте расхода</h4>{(row.goodsIds as string[]).map(id=><label key={id}>Товар {session.draft.goods.findIndex(g=>g.id===id)+1}<input inputMode="decimal" value={values[id]||''} onChange={e=>setValues({...values,[id]:e.target.value})}/></label>)}<button className="secondary" disabled={busy} onClick={()=>void onSave({scope:'cost',action:'allocations',id:row.id,allocations:values})}>Сохранить доли</button></div>;
}
