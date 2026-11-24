import {useEffect,useState} from 'react';
import type {Session} from './types';

function perUnit(value:unknown,nominal:unknown):string {
  const amount=Number(value),units=Number(nominal);if(!Number.isFinite(amount)||!Number.isFinite(units)||units<=0)return '';
  return (amount/units).toFixed(10).replace(/0+$/,'').replace(/\.$/,'');
}
function currencies(session:Session):string[] {
  const d=session.draft;return [...new Set(['USD',d.declaration.invoiceCurrency,...d.costs.map(row=>row.currency),...d.goods.filter(row=>row.dutyFormula!=='advalorem').map(row=>row.dutyCurrency),...d.rates.map(row=>row.currency)].filter((value):value is string=>typeof value==='string'&&value.trim()!==''&&value.toUpperCase()!=='KZT').map(value=>value.toUpperCase()))].sort();
}
export function GraphRateEditor({session,busy,loading,error,onManual}:{session:Session;busy:boolean;loading:boolean;error:string;onManual:(currency:string,value:string)=>Promise<void>}) {
  const rows=currencies(session);const [values,setValues]=useState<Record<string,string>>({});const [saveErrors,setSaveErrors]=useState<Record<string,string>>({});
  useEffect(()=>{const next:Record<string,string>={};for(const currency of rows){const quote=session.draft.rates.find(rate=>String(rate.currency).toUpperCase()===currency);next[currency]=perUnit(quote?.value,quote?.nominal);}setValues(next);},[session.dataRevision,rows.join(',')]);
  return <div className="graph-rates" aria-label="Курсы валют за одну единицу">{session.draft.declaration.invoiceCurrency==='KZT'&&<p className="graph-rate-row"><strong>KZT</strong><span>1 KZT = 1 KZT</span><small>Задано правилом</small></p>}{rows.map(currency=>{
    const quote=session.draft.rates.find(rate=>String(rate.currency).toUpperCase()===currency);const meta=quote?session.draft.fieldStates[`rate/${quote.id}/value`]:undefined;const manual=meta?.status==='manual';const official=meta?.status==='nbk';
    return <label className="graph-rate-row" key={currency}><strong>{currency}</strong><span>1 {currency} = <input aria-label={`Курс ${currency} к KZT`} value={values[currency]??''} disabled={busy||loading} inputMode="decimal" onChange={event=>{setSaveErrors(current=>({...current,[currency]:''}));setValues(current=>({...current,[currency]:event.target.value}));}} onBlur={async()=>{const value=(values[currency]??'').trim();if(!value||value===perUnit(quote?.value,quote?.nominal))return;try{await onManual(currency,value);setSaveErrors(current=>({...current,[currency]:''}));}catch(cause){setSaveErrors(current=>({...current,[currency]:(cause as Error).message||'Не удалось сохранить курс.'}));}}}/> KZT</span><small>{manual?'Введено вручную':official?'Получено из НБК':quote?.value?'Курс доступен':'Нет курса — введите вручную'}</small>{saveErrors[currency]&&<small className="field-error" role="alert">{saveErrors[currency]}</small>}</label>;
  })}{loading&&<p className="muted">Получаем курсы НБК…</p>}{error&&<p className="field-error" role="alert">{error}</p>}</div>;
}
