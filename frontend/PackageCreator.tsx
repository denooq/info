import { useId, useState } from 'react';
import type { Entity } from './types';

export function PackageCreator({goods,initialGoodsIds,busy,onCreate,label='Добавить грузовые места'}:{goods:Entity[];initialGoodsIds:string[];busy:boolean;onCreate:(body:Record<string,unknown>)=>Promise<void>;label?:string}) {
  const id=useId(); const [open,setOpen]=useState(false); const [name,setName]=useState(''); const [quantity,setQuantity]=useState('');
  const [goodsIds,setGoodsIds]=useState<string[]>([]); const [error,setError]=useState('');
  async function create() {
    setError('');
    if(!name.trim() || !/^[1-9]\d*$/.test(quantity) || !Number.isSafeInteger(Number(quantity)) || Number(quantity)>1000000) {setError('Укажите название и целое количество мест от 1 до 1 000 000.');return;}
    if(goods.length>0 && goodsIds.length===0) {setError('Отметьте хотя бы один товар для этой группы.');return;}
    try {
      await onCreate({scope:'package',action:'add',label:name.trim(),quantity:Number(quantity),goodsIds});
      setOpen(false); setName(''); setQuantity('');
    } catch(e) {setError((e as Error).message);}
  }
  if(!open) return <button type="button" className="text-button package-add" disabled={busy} onClick={()=>{setGoodsIds(initialGoodsIds.filter(id=>goods.some(g=>g.id===id)));setError('');setOpen(true);}}>{label}</button>;
  return <form className="package-create" aria-label="Новая группа грузовых мест" onSubmit={e=>{e.preventDefault();void create();}}>
    <label htmlFor={`${id}-name`}>Название группы<input id={`${id}-name`} autoFocus disabled={busy} maxLength={200} placeholder="Например, паллеты из CMR" value={name} onChange={e=>setName(e.target.value)}/></label>
    <label htmlFor={`${id}-quantity`}>Количество грузовых мест<input id={`${id}-quantity`} disabled={busy} inputMode="numeric" placeholder="Например, 34" value={quantity} onChange={e=>setQuantity(e.target.value)}/></label>
    <fieldset disabled={busy}><legend>Относится к товарам</legend>{goods.map((g,index)=><label className="package-goods-option" key={g.id}><input type="checkbox" checked={goodsIds.includes(g.id)} onChange={e=>setGoodsIds(e.target.checked?[...goodsIds,g.id]:goodsIds.filter(id=>id!==g.id))}/><span>Товар {index+1}: {String(g.description || 'без названия')}</span></label>)}</fieldset>
    <p className="muted">Группа войдёт в общее количество поставки один раз. Общую группу для нескольких товаров создайте один раз и отметьте нужные товары.</p>
    {error && <p className="field-error" role="alert">{error}</p>}
    <div className="footer-buttons"><button type="submit" className="secondary" disabled={busy}>Сохранить грузовые места</button><button type="button" className="text-button" disabled={busy} onClick={()=>setOpen(false)}>Отмена</button></div>
  </form>;
}
