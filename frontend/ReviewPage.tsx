import {useEffect,useState} from 'react';
import {FieldRow,required,roles} from './FieldRow';
import {PackageCreator} from './PackageCreator';
import {PackageEditor,SplitEditor} from './DraftEditors';
import {DocumentRecords} from './DocumentRecords';
import {ReviewValidation,focusReviewIssue} from './ReviewValidation';
import {ExchangeRates} from './ExchangeRates';
import {TariffInfo} from './TariffInfo';
import {GoodsGrouping} from './GoodsGrouping';
import type {Entity,Field,FieldChange,Graph,Registry,Session} from './types';

type Props={session:Session;registry:Registry;busy:boolean;onChange:(c:FieldChange)=>Promise<void>;onEntity:(b:Record<string,unknown>)=>Promise<void>;onBack:()=>void;onCalculate:()=>void;onValidate:()=>void;onRates:()=>void;ratesLoading:boolean;ratesError:string;errors:string[]};
export function ReviewPage(props:Props) {
  const {session:original,registry,busy}=props;const [goodsId,setGoodsId]=useState(original.draft.goods[0]?.id??'');const [error,setError]=useState('');
  const activeGoods=original.draft.goods.find(g=>g.id===goodsId)??original.draft.goods[0];
  useEffect(()=>{const focus=(event:Event)=>setGoodsId((event as CustomEvent<string>).detail);window.addEventListener('customs-focus-good',focus);return()=>window.removeEventListener('customs-focus-good',focus);},[]);
  const validation=original.reviewValidation??original.validation;
  const fieldStates={...original.draft.fieldStates};
  for(const g of original.graphProjection?.graphs??[])for(const entry of g.entries)if(entry.status==='auto_unavailable')fieldStates[entry.address]={...fieldStates[entry.address],status:'auto_unavailable',candidateId:fieldStates[entry.address]?.candidateId??null,evidenceIds:fieldStates[entry.address]?.evidenceIds??[]};
  const session={...original,validation,draft:{...original.draft,fieldStates}};
  async function entity(body:Record<string,unknown>){setError('');try{await props.onEntity(body);}catch(e){setError((e as Error).message);}}
  function rows(graph:Graph,field:Field):Record<string,unknown>[] {
    if(field.scope==='declaration')return [session.draft.declaration];
    if(field.scope==='goods')return activeGoods?[activeGoods]:[];
    const collection=registry.fields.scopes[field.scope]?.collection;const items=collection?session.draft[collection as keyof Session['draft']]:[];
    if(!Array.isArray(items))return [];
    if(field.scope==='party') {
      const result:Entity[]=[];let role:string|undefined=graph.entityRole;const seen=new Set();
      while(role&&!seen.has(role)){const row=items.find(r=>r.role===role);if(!row)break;seen.add(role);result.push(row);role=typeof row.sameAsRole==='string'?row.sameAsRole:undefined;}return result;
    }
    if(field.scope==='transport')return items.filter(r=>r.stage===graph.entityRole);
    if(field.scope==='rate')return items.filter(r=>r.currency===session.draft.declaration.invoiceCurrency);
    return items;
  }
  function fields(graph:Graph) {
    return <div className={`graph-fields-grid ${graph.fieldBindings.length===1?'single-field':''}`}>{graph.fieldBindings.map(id=>{
      const field=registry.fields.fields.find(f=>f.id===id);if(!field)return null;
      return rows(graph,field).filter(row=>typeof field.requiredWhen==='boolean'||required(field.requiredWhen,row)).map((row,i)=> {
        const entityId=field.scope==='declaration'?null:String(row.id);
        return <div key={`${id}-${entityId??i}`}>{field.scope==='party'&&row.role!==graph.entityRole&&<small>{roles[String(row.role)]}</small>}<FieldRow field={field} row={row} entityId={entityId} session={session} registry={registry} busy={busy} onChange={props.onChange} referenceAction={['goods.packageIds','declaration.shipmentPackageIds'].includes(id)?<PackageCreator goods={session.draft.goods} initialGoodsIds={field.scope==='goods'&&activeGoods?[activeGoods.id]:session.draft.goods.length===1?[session.draft.goods[0].id]:[]} busy={busy} onCreate={props.onEntity}/>:undefined}/></div>;
      });
    })}</div>;
  }
  function graph(graph:Graph,child=false) {
    const projection=session.graphProjection?.graphs.find(g=>g.id===graph.id);const pending=graph.calculationStage==='result'&&(!session.calculation||(session.calculation as {dataRevision:number}).dataRevision!==session.dataRevision);
    const paymentValues=graph.id==='47'&&Array.isArray(projection?.displayValue)?projection.displayValue.find((r:{goodsId:string})=>r.goodsId===activeGoods?.id) as Record<string,string>|undefined:undefined;
    return <section id={`graph-${graph.id}`} key={graph.id} data-graph-id={graph.id} className={`${child?'graph-child':'graph-card'} ${graph.fillMode==='not_applicable'?'graph-inactive':''}`} tabIndex={-1}>
      <h2>{graph.title}</h2><strong className="graph-number">Графа {graph.id==='B'?'В':graph.id.replace('a','а')}</strong>
      {graph.fillMode==='not_applicable'?<p className="muted graph-state">Не заполняется для этого профиля.</p>:graph.calculationStage==='calculation'?<p className="muted graph-state">Сведения фактической уплаты указываются на странице расчёта.</p>:pending?<p className="muted graph-state">Будет рассчитано на следующем шаге.</p>:graph.countryNameFrom?<p className="graph-value">{String(projection?.displayValue??'не удалось заполнить автоматически')}</p>:graph.id==='44'?<DocumentRecords session={session} registry={registry} busy={busy} onChange={props.onChange} onEntity={entity}/>:graph.calculationStage==='result'?<div className="graph-values">{projection?.entries.filter(e=>e.entityId===null||e.entityId===activeGoods?.id).map(e=><p key={e.address}>{String(e.value??'—')}</p>)}{graph.id==='47'&&!!session.calculation&&<p>Сбор, пошлина и НДС рассчитаны. Подробности — на странице расчёта.</p>}</div>:fields(graph)}
      {graph.id==='6'&&session.draft.packages.map(p=><PackageEditor key={p.id} row={p} busy={busy} onSave={entity} errors={validation.errors.filter(e=>e.address.startsWith(`package/${p.id}/`)).map(e=>e.message)}/>)}
      {paymentValues&&<table className="graph-payments"><thead><tr><th>Платёж</th><th>Сумма, KZT</th></tr></thead><tbody>{[['1010','fee'],['2010','duty'],['5060','vat']].map(([code,key])=><tr key={code}><td>{code}</td><td>{paymentValues[key]}</td></tr>)}</tbody></table>}
      {graph.id==='B'&&projection?.status==='calculated'&&projection.displayValue!==null&&Object.entries(projection.displayValue as Record<string,string>).map(([code,value])=><p key={code}>Уплачено {code}: {value} KZT</p>)}
      {graph.id==='23'&&session.draft.declaration.invoiceCurrency==='KZT'&&<p className="graph-value">1 KZT = 1 KZT</p>}
      {graph.id==='23'&&<ExchangeRates session={session} busy={busy} loading={props.ratesLoading} error={props.ratesError} onRefresh={props.onRates}/>}
      {graph.id==='33'&&activeGoods&&<TariffInfo row={activeGoods}/>}
      {graph.id==='31'&&activeGoods&&Array.isArray(activeGoods.sourceLineIds)&&activeGoods.sourceLineIds.length>1&&<SplitEditor row={activeGoods} session={session} busy={busy} onSave={entity}/>}
      {graph.id==='31'&&<GoodsGrouping session={session} busy={busy} onMerge={entity}/>}
      {registry.graphs?.graphs.filter(g=>g.parentGraph===graph.id).map(g=>graphElement(g))}
    </section>;
  }
  const graphElement=(g:Graph)=>graph(g,true);
  return <div className="review-page"><div className="page-intro"><h1>Проверьте данные декларации</h1><p>Заполните обязательные графы и выберите значения при расхождениях.</p></div>
    {(error||props.errors.length>0||session.error)&&<div className="notice error" role="alert">{error||props.errors.join(' ')||session.error?.message}</div>}
    {session.documents.some(d=>d.status==='error')&&<p className="notice">Не распознаны: {session.documents.filter(d=>d.status==='error').map(d=>d.name).join(', ')}. Заполните оставшиеся графы вручную или повторите эти файлы на странице документов.</p>}
    {!!session.mappingIssues?.length&&<details className="notice"><summary>Замечания сопоставления: {session.mappingIssues.length}</summary>{[...new Set(session.mappingIssues.map(i=>i.message))].map(message=><p key={message}>{message}</p>)}</details>}
    <ReviewValidation session={session} registry={registry}/>
    <div className="goods-switcher"><label>Товар <select aria-label="Товар для граф 31–47" value={activeGoods?.id??''} onChange={e=>setGoodsId(e.target.value)}>{session.draft.goods.map((g,i)=><option key={g.id} value={g.id}>{i+1}. {String(g.description??'Без названия').slice(0,100)}</option>)}</select></label><button className="secondary" disabled={busy} onClick={()=>void entity({scope:'goods',action:'add'})}>Добавить товар</button><button className="text-button" disabled={busy||!activeGoods||session.draft.goods.length<2} onClick={()=>void entity({scope:'goods',action:'remove',id:activeGoods?.id})}>Удалить товар</button></div>
    <div className="graphs-grid">{registry.graphs?.graphs.filter(g=>g.parentGraph===null).map(g=>graph(g))}</div>
    <div className="review-footer"><div><strong>{validation.valid?'Обязательные графы заполнены':`Ошибок: ${validation.count}`}</strong>{validation.errors[0]&&<button className="text-button" onClick={()=>focusReviewIssue(validation.errors[0],session,registry)}>Перейти к первой ошибке</button>}</div><div className="footer-buttons"><button className="secondary" disabled={busy} onClick={props.onBack}>К документам</button><button className="secondary" disabled={busy} onClick={props.onValidate}>Проверить заполнение</button><button className="primary" disabled={busy||props.ratesLoading||!validation.valid} onClick={props.onCalculate}>К расчёту</button></div></div>
  </div>;
}
