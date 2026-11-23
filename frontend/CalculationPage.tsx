import {useEffect,useState} from 'react';
import {FieldRow,required} from './FieldRow';
import {TariffInfo,tariffFieldVisible} from './TariffInfo';
import {ExchangeRates} from './ExchangeRates';
import {AllocationEditor} from './DraftEditors';
import {ReviewValidation} from './ReviewValidation';
import type {Entity,Field,FieldChange,Registry,Session} from './types';

export type Calculation={dataRevision:number;hash:string;goodsCount:number;sheetCount:number;packageCount:number;items:Record<string,unknown>[];totals:Record<string,string>;costs:{id:string;kind:string;converted:string;effective:string;allocations:Record<string,string>}[];actualPaid:Record<string,string>;graphB:Record<string,string>;consistent:boolean;proposals:Record<string,{value:string;calculated:string;matches:boolean}>};
const labels:Record<string,string>={nationalInvoiceValue:'Цена по инвойсу, KZT',indirectValue:'Косвенные платежи, KZT',basisValue:'Основа стоимости, KZT',additionValue:'Начисления, KZT',deductionValue:'Вычеты, KZT',customsValue:'Таможенная стоимость, KZT',customsValueUsd:'Таможенная стоимость, USD',statisticalValue:'Статистическая стоимость, USD',fee:'Таможенный сбор 1010, KZT',duty:'Пошлина 2010, KZT',vatBase:'База НДС, KZT',vat:'НДС 5060, KZT',accruedTotal:'Всего начислено, KZT',invoiceTotal:'По инвойсу'};
const editable=['customsValue','statisticalValue','fee','duty','vat','accruedTotal'];
type Props={session:Session;registry:Registry;busy:boolean;errors:string[];onChange:(c:FieldChange)=>Promise<void>;onEntity:(b:Record<string,unknown>)=>Promise<void>;onRecalculate:()=>void;onPropose:(id:string,key:string,value:string|null)=>Promise<void>;onBack:()=>void;onRates:()=>void;ratesLoading:boolean;ratesError:string};
export function CalculationPage(props:Props) {
  const {session:original,registry,busy}=props;const [error,setError]=useState('');const session={...original,validation:original.calculationValidation??original.validation};
  const calc=session.calculation as Calculation|null;const current=!!calc&&calc.dataRevision===session.dataRevision;
  const reviewIds=new Set(registry.graphs?.graphs.filter(g=>g.calculationStage==='review'&&g.fillMode!=='not_applicable').flatMap(g=>g.fieldBindings)??[]);
  const inactive=new Set(['3','4','7','10','13','27','28','40','48','49','50','51','52','53']);
  const parameters=registry.fields.fields.filter(f=>['declaration','goods'].includes(f.scope)&&!reviewIds.has(f.id)&&!f.acquisition.includes('calculated')&&f.id!=='goods.sourceLineIds'&&!(f.dtGraphs.length>0&&f.dtGraphs.every(id=>inactive.has(id))));
  async function entity(body:Record<string,unknown>){setError('');try{await props.onEntity(body);}catch(e){setError((e as Error).message);}}
  function fields(list:Field[],row:Record<string,unknown>,id:string|null){return <div className="fields-grid">{list.filter(f=>tariffFieldVisible(f,row)&&(typeof f.requiredWhen==='boolean'||required(f.requiredWhen,row))).map(f=><FieldRow key={f.id} field={f} row={row} entityId={id} session={session} registry={registry} busy={busy} onChange={props.onChange}/>)}</div>;}
  function collection(scope:'rate'|'cost'|'payment',title:string,rows:Entity[]) {
    return <section className="form-section" id={`section-${scope}`}><div className="section-heading"><h2>{title}</h2><button className="secondary" disabled={busy} onClick={()=>void entity({scope,action:'add'})}>Добавить</button></div>{rows.map((row,i)=><article className="entity-block" key={row.id}><div className="entity-heading"><h3>{title} · {i+1}</h3><button className="text-button" disabled={busy} onClick={()=>void entity({scope,action:'remove',id:row.id})}>Удалить</button></div>{fields(registry.fields.fields.filter(f=>f.scope===scope),row,row.id)}{scope==='cost'&&row.allocationBasis==='manual'&&<AllocationEditor row={row} session={session} busy={busy} onSave={entity}/>}</article>)}</section>;
  }
  return <div className="calculation-page"><div className="page-intro"><h1>Расчёт декларации</h1><p>Проверьте параметры метода 1, расходы, курсы и ставки.</p></div>
    {(error||props.errors.length>0)&&<div className="notice error" role="alert">{error||props.errors.join(' ')}</div>}
    {!original.reviewValidation?.valid&&original.reviewValidation&&<div className="notice error">Исходные графы изменены. Вернитесь к проверке и заполните обязательные реквизиты.</div>}
    <ReviewValidation session={session} registry={registry}/>
    <section className="form-section" id="section-declaration"><h2>ДТС-1 · метод стоимости сделки</h2>{fields(parameters.filter(f=>f.scope==='declaration'),session.draft.declaration,null)}</section>
    <section className="form-section" id="section-party"><h2>Продавец и покупатель для ДТС</h2>{session.draft.parties.filter(p=>['seller','buyer'].includes(String(p.role))).map(row=><article className="entity-block" key={row.id}><h3>{row.role==='seller'?'Продавец':'Покупатель'}</h3>{fields(registry.fields.fields.filter(f=>f.scope==='party'&&f.key!=='role'),row,row.id)}</article>)}</section>
    {session.draft.goods.map((row,i)=><section className="form-section" id={`calculation-goods-${row.id}`} key={row.id}><h2>Ставки и параметры · товар {i+1}</h2><TariffInfo row={row}/>{fields(parameters.filter(f=>f.scope==='goods'),row,row.id)}</section>)}
    {collection('cost','Компоненты стоимости ДТС',session.draft.costs)}
    <ExchangeRates session={session} busy={busy} loading={props.ratesLoading} error={props.ratesError} onRefresh={props.onRates}/>
    {collection('rate','Курсы валют',session.draft.rates)}
    {session.draft.declaration.paymentFactsAvailable===true&&collection('payment','Фактическая уплата',session.draft.payments)}
    {!current&&<div className="notice">Расчёт ещё не выполнен для текущих данных.</div>}
    {calc&&<><section className="calculation-summary"><span>Товаров <strong>{calc.goodsCount}</strong></span><span>Листов ДТ <strong>{calc.sheetCount}</strong></span><span>Грузовых мест <strong>{calc.packageCount}</strong></span></section>{calc.items.map((item,i)=><section className="form-section" key={String(item.id)}><h2>Расчёт · товар {i+1}</h2><p className="formula-note">Стоимость = цена по курсу + косвенные платежи + начисления − вычеты. Пошлина: {String(item.dutyFormulaText??'')}. НДС = (стоимость + пошлина + сбор) × ставка / 100.</p>
      {Array.isArray(item.dutyTrace)&&<details className="tariff-trace"><summary>Компоненты пошлины в KZT</summary>{(item.dutyTrace as Record<string,string>[]).map((part,index)=><p key={index}>{part.kind}: {part.amount} KZT</p>)}</details>}
      {Object.entries(labels).filter(([key])=>key in item).map(([key,label])=><ResultRow key={`${calc.hash}-${key}`} label={label} value={String(item[key])} editable={editable.includes(key)} proposal={calc.proposals[`${item.id}/${key}`]} busy={busy||!current} onSave={async value=>{setError('');try{await props.onPropose(String(item.id),key,value);}catch(e){setError((e as Error).message);}}}/>)}</section>)}
      {calc.costs.length>0&&<section className="form-section"><h2>Распределение компонентов стоимости</h2>{calc.costs.map(c=><div className="cost-result" key={c.id}><strong>{c.kind}: {c.converted} KZT</strong><p>Включено: {c.effective} KZT</p>{Object.entries(c.allocations).map(([id,value])=><p key={id}>Товар {session.draft.goods.findIndex(g=>g.id===id)+1}: {value} KZT</p>)}</div>)}</section>}
      <section className="form-section"><h2>Итоги</h2>{Object.entries(calc.totals).map(([key,value])=><div className="total-row" key={key}><span>{labels[key]??key}</span><strong>{value}</strong></div>)}<p className="muted">Начисление не подтверждает уплату. Графа «В» заполняется по платёжным документам.</p>{Object.entries(calc.graphB).map(([code,value])=><p key={code}>Уплачено {code}: {value} KZT</p>)}</section></>}
    {calc&&!calc.consistent&&<div className="notice error" role="alert">Ручные итоги расходятся с формулой. Измените исходные параметры и пересчитайте либо примите рассчитанные значения.</div>}
    <div className="review-footer"><button className="secondary" disabled={busy} onClick={props.onBack}>К проверке данных</button><button className="primary" disabled={busy||props.ratesLoading||!session.validation.valid} onClick={props.onRecalculate}>{current?'Пересчитать':'Рассчитать'}</button></div>
  </div>;
}
function ResultRow({label,value,editable,proposal,busy,onSave}:{label:string;value:string;editable:boolean;proposal?:{value:string;calculated:string;matches:boolean};busy:boolean;onSave:(v:string|null)=>Promise<void>}) {
  const [local,setLocal]=useState(proposal?.value??value);
  useEffect(()=>setLocal(proposal?.value??value),[value,proposal?.value]);
  return <div className="result-row"><label>{label}<input value={local} disabled={busy} readOnly={!editable} onChange={e=>setLocal(e.target.value)} onBlur={()=>{if(local!==(proposal?.value??value))void onSave(local);}}/></label>{proposal&&!proposal.matches&&<div className="result-discrepancy"><span>По формуле: {value}. Измените операнды выше.</span><button className="text-button" disabled={busy} onClick={()=>{setLocal(value);void onSave(null);}}>Принять рассчитанное</button></div>}</div>;
}
