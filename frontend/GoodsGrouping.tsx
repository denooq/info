import {useState} from 'react';
import type {Session} from './types';
export function GoodsGrouping({session,busy,onMerge}:{session:Session;busy:boolean;onMerge:(b:Record<string,unknown>)=>Promise<void>}) {
  const [selected,setSelected]=useState<string[]>([]);const [mode,setMode]=useState('same-item');
  if(session.draft.goods.length<2)return null;
  return <details className="goods-grouping"><summary>Объединить сведения о товарах</summary>{session.draft.goods.map((g,i)=><label className="reference-line" key={g.id}><input type="checkbox" disabled={busy} checked={selected.includes(g.id)} onChange={e=>setSelected(e.target.checked?[...selected,g.id]:selected.filter(id=>id!==g.id))}/>{i+1}. {String(g.description??'Без названия')}</label>)}<select aria-label="Способ объединения товаров" value={mode} onChange={e=>setMode(e.target.value)}><option value="same-item">Повторные сведения об одном товаре</option><option value="sum">Отдельные строки: суммировать</option></select><button className="secondary" disabled={busy||selected.filter(id=>session.draft.goods.some(g=>g.id===id)).length<2} onClick={()=>void onMerge({scope:'goods',action:'merge',ids:selected,mode,confirmed:true}).then(()=>setSelected([]))}>Объединить выбранные</button></details>;
}
