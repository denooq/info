import { useState } from 'react';
import { domId, FieldRow, roles } from './FieldRow';
import type { Entity, FieldChange, Registry, Session } from './types';

type Props = { session:Session; registry:Registry; busy:boolean; onChange:(c:FieldChange)=>Promise<void>; onEntity:(body:Record<string,unknown>)=>Promise<void>; onBack:()=>void; onCalculate:()=>void; errors:string[] };
export function ReviewPage(props:Props) {
  const {session,registry,busy,onChange,onEntity}=props; const [selected,setSelected]=useState<string[]>([]); const [mergeMode,setMergeMode]=useState('same-item'); const [localError,setLocalError]=useState('');
  async function entity(body:Record<string,unknown>) { setLocalError(''); try { await onEntity(body); setSelected([]); } catch(e){setLocalError((e as Error).message);} }
  function fields(scope:string,row:Record<string,unknown>,id:string|null,filter:(f:Registry['fields']['fields'][number])=>boolean=()=>true) {
    return registry.fields.fields.filter(f=>f.scope===scope && !f.acquisition.includes('calculated') && filter(f)).map(f=><FieldRow key={f.id} field={f} row={row} entityId={id} session={session} registry={registry} busy={busy} onChange={onChange}/>);
  }
  function collection(scope:string,title:string,rows:Entity[]) { return <section className="form-section" id={`section-${scope}`}><div className="section-heading"><h2>{title}</h2><button className="secondary" disabled={busy} onClick={()=>void entity({scope,action:'add'})}>Добавить</button></div>{rows.length===0 && <p className="muted">Нет строк. Добавьте при наличии соответствующих данных.</p>}{rows.map((row,i)=><article className="entity-block" key={row.id}><div className="entity-heading"><h3>{title} · {i+1}</h3><button className="text-button" disabled={busy} onClick={()=>void entity({scope,action:'remove',id:row.id})}>Удалить</button></div>{fields(scope,row,row.id)}{scope==='cost' && row.allocationBasis==='manual' && <AllocationEditor row={row} session={session} busy={busy} onSave={body=>entity(body)}/>}</article>)}</section>; }
  const initial=registry.fields.fields.filter(f=>f.scope==='declaration' && !f.acquisition.includes('calculated'));
  const dtsIds=new Set(initial.filter(f=>f.dtsFields?.length || f.id.includes('Applicable')).map(f=>f.id));
  function firstError() {
    const error=session.validation.errors[0]; if(!error)return;
    const target=document.getElementById(domId(error.address)) || document.getElementById(`section-${error.address.split('/')[0]}`);
    if(target){target.closest('details')?.setAttribute('open','');target.scrollIntoView({behavior:'smooth',block:'center'});target.focus();}
  }
  return <div className="review-page"><div className="page-intro"><h1>Проверьте данные декларации</h1><p>Выберите подходящие значения из документов или заполните поля вручную. Звёздочкой отмечены обязательные поля.</p></div>
    {(localError || props.errors.length>0) && <div className="notice error" role="alert">{localError || props.errors.join(' ')}</div>}
    <section className="form-section" id="section-declaration"><h2>Поставка и декларация</h2>{fields('declaration',session.draft.declaration,null,f=>!dtsIds.has(f.id))}</section>
    <section className="form-section" id="section-party"><h2>Участники</h2>{session.draft.parties.map(row=><article className="entity-block" key={row.id}><h3>{roles[String(row.role)]}</h3>{fields('party',row,row.id,f=> f.key==='sameAsRole' || !row.sameAsRole || f.key==='role')}</article>)}</section>
    <section className="form-section" id="section-transport"><h2>Транспорт</h2>{session.draft.transports.map(row=><article className="entity-block" key={row.id}><h3>{roles[String(row.stage)]}</h3>{fields('transport',row,row.id)}</article>)}</section>
    <section className="form-section" id="section-goods"><div className="section-heading"><h2>Товары</h2><button className="secondary" disabled={busy} onClick={()=>void entity({scope:'goods',action:'add'})}>Добавить товар</button></div>
      {session.draft.goods.length>1 && <div className="group-actions"><select aria-label="Способ объединения" value={mergeMode} onChange={e=>setMergeMode(e.target.value)}><option value="same-item">Повторные сведения об одном товаре</option><option value="sum">Отдельные строки: суммировать</option></select><button className="secondary" disabled={busy || selected.length<2} onClick={()=>void entity({scope:'goods',action:'merge',ids:selected,mode:mergeMode,confirmed:true})}>Объединить выбранные</button></div>}
      {session.draft.goods.map((row,i)=><article className="entity-block" key={row.id}><div className="entity-heading"><label className="goods-title"><input type="checkbox" checked={selected.includes(row.id)} onChange={e=>setSelected(e.target.checked?[...selected,row.id]:selected.filter(id=>id!==row.id))}/>Товар {i+1}</label><button className="text-button" disabled={busy} onClick={()=>void entity({scope:'goods',action:'remove',id:row.id})}>Удалить товар</button></div>{fields('goods',row,row.id)}{Array.isArray(row.sourceLineIds) && row.sourceLineIds.length>1 && <SplitEditor row={row} session={session} busy={busy} onSave={body=>entity(body)}/>}</article>)}
    </section>
    <section className="form-section" id="section-package"><div className="section-heading"><h2>Грузовые места поставки</h2><button className="secondary" disabled={busy} onClick={()=>void entity({scope:'package',action:'add'})}>Добавить группу мест</button></div><p className="muted">Одна паллета или группа мест может относиться к нескольким товарам. В общем количестве она учитывается один раз.</p>{session.draft.packages.map(p=><PackageEditor key={p.id} row={p} busy={busy} onSave={body=>entity(body)}/>)}</section>
    {collection('document','Документы',session.draft.documents)}
    <section className="form-section"><h2>ДТС-1 · метод стоимости сделки</h2>{fields('declaration',session.draft.declaration,null,f=>dtsIds.has(f.id))}</section>
    {collection('cost','Компоненты стоимости ДТС',session.draft.costs)}
    {collection('rate','Курсы валют',session.draft.rates)}
    {session.draft.declaration.paymentFactsAvailable===true && collection('payment','Фактическая уплата',session.draft.payments)}
    <section className="form-section"><h2>Параметры XML по примеру</h2>{fields('keden',session.draft.keden,null)}</section>
    <div className="review-footer"><div><strong>{session.validation.valid?'Обязательные данные заполнены':`Ошибок: ${session.validation.count}`}</strong>{!session.validation.valid && <button className="text-button" onClick={firstError}>Перейти к первой ошибке</button>}</div><div className="footer-buttons"><button className="secondary" disabled={busy} onClick={props.onBack}>К документам</button><button className="primary" disabled={busy || !session.validation.valid} onClick={props.onCalculate}>Рассчитать</button></div></div><p className="session-note">Сессия действует до {new Date(session.expiresAt*1000).toLocaleTimeString('ru-RU')}. После этого файлы и данные удаляются.</p>
  </div>;
}
function PackageEditor({row,busy,onSave}:{row:Entity;busy:boolean;onSave:(b:Record<string,unknown>)=>Promise<void>}) {
  const [label,setLabel]=useState(String(row.label||'')); const [quantity,setQuantity]=useState(String(row.quantity||''));
  return <div className="package-row"><input aria-label="Название группы мест" placeholder="Например, паллеты из CMR" value={label} onChange={e=>setLabel(e.target.value)}/><input aria-label="Количество мест в группе" placeholder="Количество" value={quantity} onChange={e=>setQuantity(e.target.value)} inputMode="numeric"/><button className="secondary" disabled={busy} onClick={()=>void onSave({scope:'package',action:'update',id:row.id,label,quantity:Number(quantity)})}>Сохранить</button><button className="text-button" disabled={busy} onClick={()=>void onSave({scope:'package',action:'remove',id:row.id})}>Удалить</button></div>;
}
function SplitEditor({row,session,busy,onSave}:{row:Entity;session:Session;busy:boolean;onSave:(b:Record<string,unknown>)=>Promise<void>}) {
  const [lines,setLines]=useState<string[]>([]); const ids=row.sourceLineIds as string[];
  return <details className="split-editor"><summary>Разделить исходные строки на товары</summary><p className="muted">Выберите строки для нового товара. Массы и стоимости обеих групп нужно будет заполнить и подтвердить заново.</p>{ids.map(id=><label className="reference-line" key={id}><input type="checkbox" checked={lines.includes(id)} onChange={e=>setLines(e.target.checked?[...lines,id]:lines.filter(l=>l!==id))}/>{session.draft.sourceLines[id]?.quote || id}</label>)}<button className="secondary" disabled={busy || lines.length===0 || lines.length===ids.length} onClick={()=>void onSave({scope:'goods',action:'split',id:row.id,sourceLineIds:lines})}>Отделить выбранные строки</button></details>;
}
function AllocationEditor({row,session,busy,onSave}:{row:Entity;session:Session;busy:boolean;onSave:(b:Record<string,unknown>)=>Promise<void>}) {
  const [values,setValues]=useState<Record<string,string>>((row.allocations as Record<string,string>)||{});
  return <div className="allocation-editor"><h4>Ручные доли в валюте расхода</h4>{(row.goodsIds as string[]).map(id=><label key={id}>Товар {session.draft.goods.findIndex(g=>g.id===id)+1}<input inputMode="decimal" value={values[id]||''} onChange={e=>setValues({...values,[id]:e.target.value})}/></label>)}<button className="secondary" disabled={busy} onClick={()=>void onSave({scope:'cost',action:'allocations',id:row.id,allocations:values})}>Сохранить доли</button></div>;
}
