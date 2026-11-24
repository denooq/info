import {useEffect,useState} from 'react';
import {FieldRow,required} from './FieldRow';
import {GraphCard} from './GraphCard';
import {GraphTextEditor} from './GraphTextEditor';
import {DocumentRecords} from './DocumentRecords';
import {TnvedSearch} from './TnvedSearch';
import {SourcePopover} from './SourcePopover';
import {PackageCreator} from './PackageCreator';
import {PackageEditor,SplitEditor} from './DraftEditors';
import {focusReviewIssue} from './ReviewValidation';
import {GraphRateEditor} from './GraphRateEditor';
import {TariffInfo} from './TariffInfo';
import {GoodsGrouping} from './GoodsGrouping';
import type {CompactGraphValue,Entity,FieldChange,Graph,Registry,Session} from './types';

type Props={session:Session;registry:Registry;busy:boolean;onChange:(c:FieldChange)=>Promise<void>;onEntity:(b:Record<string,unknown>)=>Promise<void>;onBack:()=>void;onCalculate:()=>void;onValidate:()=>void;onRates:()=>void;onManualRate:(currency:string,value:string)=>Promise<void>;ratesLoading:boolean;ratesError:string;errors:string[]};
export function ReviewPage(props:Props) {
  const {session:original,registry,busy}=props;const [goodsId,setGoodsId]=useState(original.draft.goods[0]?.id??'');const [error,setError]=useState('');
  const activeGoods=original.draft.goods.find(g=>g.id===goodsId)??original.draft.goods[0];
  useEffect(()=>{const focus=(event:Event)=>setGoodsId((event as CustomEvent<string>).detail);window.addEventListener('customs-focus-good',focus);return()=>window.removeEventListener('customs-focus-good',focus);},[]);
  const validation=original.reviewValidation??original.validation;const session={...original,validation};
  async function entity(body:Record<string,unknown>){setError('');try{await props.onEntity(body);}catch(e){setError((e as Error).message);}}
  function rowFor(input:CompactGraphValue['inputs'][number]):Record<string,unknown>|undefined {
    const field=registry.fields.fields.find(f=>f.id===input.fieldId);if(!field)return;
    if(field.scope==='declaration')return {...session.draft.declaration,[field.key]:input.value};
    const collection=registry.fields.scopes[field.scope]?.collection;const rows=collection?session.draft[collection as keyof Session['draft']]:null;
    if(!Array.isArray(rows))return;const row=rows.find(r=>r.id===input.entityId);return row?{...row,[field.key]:input.value}:undefined;
  }
  function controls(item:CompactGraphValue) {
    const inputs=item.inputs.filter(input=>{
      const field=registry.fields.fields.find(f=>f.id===input.fieldId);const row=rowFor(input);if(!field||!row)return false;
      if(field.scope==='goods'&&input.entityId!==activeGoods?.id)return false;
      if(typeof field.requiredWhen!=='boolean'&&!required(field.requiredWhen,row))return false;
      if(item.graphId==='6') {
        if(field.key==='sourcePackageCount'&&session.draft.packages.length>0&&input.value===null)return false;
        if(field.key==='shipmentPackageIds'&&session.draft.packages.length===1&&Array.isArray(input.value)&&input.value.length===1)return false;
        if(field.key==='packageIds'&&session.draft.packages.length===1&&session.draft.goods.length===1&&Array.isArray(input.value)&&input.value.length===1)return false;
      }
      if(item.graphId==='23'&&['source','confirmed'].includes(field.key)&&row.confirmed===true&&session.draft.fieldStates[input.address]?.status==='nbk')return false;
      return true;
    });
    return <div className={`compact-controls ${inputs.length===1?'single':''}`}>{inputs.map(input=>{const field=registry.fields.fields.find(f=>f.id===input.fieldId)!;return <FieldRow key={input.address} field={field} row={rowFor(input)!} entityId={input.entityId} session={session} registry={registry} busy={busy} onChange={props.onChange}/>;})}</div>;
  }
  function graph(g:Graph) {
    const item=session.graphProjection?.editors?.find(e=>e.graphId===g.id&&(e.entityId===null||e.entityId===activeGoods?.id));
    const editor=registry.editors?.graphs.find(e=>e.graphId===g.id);const text=item&&['text','code-text','documents','signature'].includes(item.kind);
    const current=!!session.calculation&&(session.calculation as {dataRevision:number}).dataRevision===session.dataRevision;
    const b=session.graphProjection?.graphs.find(e=>e.id==='B');
    const country=['15','17'].includes(g.id)?session.graphProjection?.graphs.find(row=>row.id===g.id)?.displayValue as {code?:string;name?:string}|null:null;
    return <GraphCard key={g.id} graph={g}>
      {!item?<p className="muted">Нет данных редактора. Проверьте, что frontend и backend обновлены вместе.</p>:<>
        {item.kind==='inactive'?<p className="graph-state">Не заполняется</p>:<>
          {g.id==='15'||g.id==='17'?<p className="graph-country-name">{country?.name??item.value??'не удалось заполнить автоматически'}</p>:g.id==='23'?<GraphRateEditor session={session} busy={busy} loading={props.ratesLoading} error={props.ratesError} onManual={props.onManualRate}/>:controls(item)}
          {item.kind==='documents'&&<DocumentRecords session={session} registry={registry} busy={busy} goodsId={activeGoods?.id??null} onChange={props.onChange} onEntity={props.onEntity}/>}
          {text&&item.kind!=='documents'&&<GraphTextEditor key={item.address} item={item} graph={g} session={session} busy={busy} hint={editor?.hint??undefined} onChange={props.onChange}/>}
          {item.kind==='readonly'&&!['15','17','47'].includes(g.id)&&<><input className="graph-readonly" aria-labelledby={`graph-title-${g.id}`} readOnly value={item.status==='pending_calculation'?'Будет рассчитано на следующем шаге':item.value??'не удалось заполнить автоматически'}/><small className="muted">{item.status==='pending_calculation'?'Ожидает расчёта':g.calculationStage==='result'?'Рассчитано':'Задано профилем / правилом'}</small></>}
          {g.id==='22'&&<p className="compact-meta">Общая сумма: {String(session.draft.declaration.invoiceTotal??'—')} {String(session.draft.declaration.invoiceCurrency??'')}</p>}
          {g.id==='6'&&<>
            {session.draft.packages.filter(p=>session.draft.packages.length>1||p._fromCount!==true).map(p=><PackageEditor key={p.id} row={p} busy={busy} onSave={entity} errors={validation.errors.filter(e=>e.address.startsWith(`package/${p.id}/`)).map(e=>e.message)}/>)}
            <PackageCreator goods={session.draft.goods} initialGoodsIds={activeGoods?[activeGoods.id]:[]} busy={busy} onCreate={props.onEntity}/>
          </>}
          {g.id==='33'&&activeGoods&&<><TnvedSearch row={activeGoods} busy={busy} onSelect={props.onChange}/>{activeGoods._tariff&&<SourcePopover label="Ставка и единицы ТН ВЭД"><TariffInfo row={activeGoods}/></SourcePopover>}</>}
          {g.id==='31'&&activeGoods&&<details className="compact-grouping"><summary>Состав товара</summary>{Array.isArray(activeGoods.sourceLineIds)&&activeGoods.sourceLineIds.length>1&&<SplitEditor row={activeGoods} session={session} busy={busy} onSave={entity}/>}<GoodsGrouping session={session} busy={busy} onMerge={entity}/></details>}
          {g.id==='41'&&activeGoods?.additionalUnitRequired===false&&activeGoods?.secondaryAdditionalUnitRequired===false&&<p className="graph-state">Дополнительная единица не требуется.</p>}
          {g.id==='47'&&<>{!current?<p className="graph-state">Будет рассчитано на следующем шаге.</p>:<table className="graph-payments"><thead><tr><th>Платёж</th><th>KZT</th></tr></thead><tbody>{(session.graphProjection?.graphs.find(e=>e.id==='47')?.displayValue as {goodsId:string;fee:string;duty:string;vat:string}[]|undefined)?.filter(i=>i.goodsId===activeGoods?.id).flatMap(i=>[['1010',i.fee],['2010',i.duty],['5060',i.vat]].map(([code,value])=><tr key={code}><td>{code}</td><td>{value}</td></tr>))}</tbody></table>}
            {session.draft.statutoryRules&&<SourcePopover label="Нормативные основания">{Object.entries(session.draft.statutoryRules.sources).map(([kind,url])=><span key={kind}>{kind}: <a href={url} target="_blank" rel="noreferrer">Официальный источник</a></span>)}<span>{session.draft.statutoryRules.version}; {session.draft.statutoryRules.date}</span></SourcePopover>}
          </>}
        </>}
      </>}
      {registry.graphs?.graphs.filter(c=>c.id==='B'&&c.parentGraph===g.id).map(c=><section key={c.id} id={`graph-${c.id}`} data-graph-id={c.id} className="compact-position"><strong>Графа В</strong><span>{b?.status==='not_applicable'?'Фактическая уплата не заявлена':Object.entries((b?.displayValue??{}) as Record<string,string>).map(([code,v])=>`${code}: ${v} KZT`).join('; ')||'Уплата указывается на странице расчёта'}</span></section>)}
    </GraphCard>;
  }
  function childGraph(g:Graph) {
    const country=session.graphProjection?.graphs.find(row=>row.id===g.parentGraph)?.displayValue as {code?:string;name?:string}|null;
    const fieldId=g.id==='15a'?'declaration.dispatchCountry':g.id==='17a'?'declaration.destinationCountry':null;
    const projected=fieldId?session.graphProjection?.graphs.find(row=>row.id===g.id)?.entries.find(entry=>entry.fieldId===fieldId):undefined;
    const field=fieldId?registry.fields.fields.find(candidate=>candidate.id===fieldId):undefined;
    return <GraphCard key={g.id} graph={g} child>
      {g.id==='15a'&&field&&projected?<><FieldRow field={field} row={{...session.draft.declaration,[field.key]:projected.value}} entityId={null} session={session} registry={registry} busy={busy} onChange={props.onChange}/><p className="graph-country-name">{country?.name??'Страна не определена'}</p></>:<span>{[projected?.value??country?.code,country?.name].filter(Boolean).join(' — ')||'не удалось заполнить автоматически'}</span>}
    </GraphCard>;
  }
  return <div className="review-page"><div className="page-intro"><h1>Проверьте данные декларации</h1><p>Заполните обязательные графы и выберите значения при расхождениях.</p></div>
    {(error||props.errors.length>0||session.error)&&<div className="notice error" role="alert">{error||props.errors.join(' ')||session.error?.message}</div>}
    {session.migrationNotices?.map(notice=><details className="notice compact-migration" key={notice.id}><summary>{notice.message}</summary>{notice.changes.map(c=><p key={c.address}>{c.address}: {String(c.previous??'—')} → {String(c.current??'не определено')}</p>)}</details>)}
    {session.documents.some(d=>d.status==='error')&&<p className="notice">Часть документов не распознана. Заполните оставшиеся графы вручную или повторите эти файлы на странице документов.</p>}
    <div className="goods-switcher"><label>Товар <select aria-label="Товар для граф 31–47" value={activeGoods?.id??''} onChange={e=>setGoodsId(e.target.value)}>{session.draft.goods.map((g,i)=><option key={g.id} value={g.id}>{i+1}. {String(g.description??'Без названия').slice(0,100)}</option>)}</select></label><button className="secondary" disabled={busy} onClick={()=>void entity({scope:'goods',action:'add'})}>Добавить товар</button><button className="text-button" disabled={busy||!activeGoods||session.draft.goods.length<2} onClick={()=>void entity({scope:'goods',action:'remove',id:activeGoods?.id})}>Удалить товар</button></div>
    <div className="graphs-grid">{registry.graphs?.graphs.filter(g=>g.parentGraph===null).flatMap(g=>[graph(g),...registry.graphs!.graphs.filter(child=>child.parentGraph===g.id&&['15a','17a'].includes(child.id)).map(childGraph)])}</div>
    <div className="review-footer"><div><strong>{validation.valid?'Обязательные графы заполнены':`Ошибок: ${validation.count}`}</strong>{validation.errors[0]&&<button className="text-button" onClick={()=>focusReviewIssue(validation.errors[0],session,registry)}>Перейти к первой ошибке</button>}</div><div className="footer-buttons"><button className="secondary" disabled={busy} onClick={props.onBack}>К документам</button><button className="secondary" disabled={busy} onClick={props.onValidate}>Проверить заполнение</button><button className="primary" disabled={busy||props.ratesLoading||!validation.valid} onClick={props.onCalculate}>К расчёту</button></div></div>
  </div>;
}
