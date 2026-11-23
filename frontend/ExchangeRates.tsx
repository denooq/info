import type {Session} from './types';

export function rateRequestKey(session:Session):string {
  const d=session.draft;const date=String(d.declaration.signatureDate || '');
  const currencies=['USD',d.declaration.invoiceCurrency,...d.costs.map(c=>c.currency),...d.goods.filter(g=>g.dutyFormula!=='advalorem').map(g=>g.dutyCurrency),...d.rates.map(r=>r.currency)];
  return `${date}|${[...new Set(currencies.filter(v=>typeof v==='string' && v.trim() && v!=='KZT').map(v=>String(v).trim().toUpperCase()))].sort().join(',')}`;
}
export function ratesComplete(session:Session):boolean {
  const [date,codes]=rateRequestKey(session).split('|');
  return !!date && codes.split(',').every(currency=>session.draft.rates.some(r=>r.currency===currency && r.date===date && r.value!==null && r.nominal!==null && r.source && r.confirmed===true));
}
export function ExchangeRates({session,busy,loading,error,onRefresh}:{session:Session;busy:boolean;loading:boolean;error:string;onRefresh:()=>void}) {
  if(!session.capabilities?.nbkRates)return null;
  const date=String(session.draft.declaration.signatureDate || '');const info=session.exchangeRates?.date===date?session.exchangeRates:undefined;
  const url=info?.url || `https://nationalbank.kz/rss/get_rates.cfm?fdate=${date.split('-').reverse().join('.')}`;
  return <aside className="exchange-rates-info" aria-label="Курсы Национального Банка">
    <p>{loading?'Получаем курсы НБК…':`Курсы на дату составления: ${date.split('-').reverse().join('.')}`}</p>
    {(error || info?.error) && <p className="field-error" role="alert">{error || info?.error?.message}</p>}
    {!!info?.missingCurrencies.length && <p className="field-error">Нет курсов НБК: {info.missingCurrencies.join(', ')}. Заполните их вручную или повторите загрузку.</p>}
    {!!info?.protectedManual.length && <p>Сохранены ручные курсы: {info.protectedManual.join(', ')}.</p>}
    <p><a href={url} target="_blank" rel="noreferrer">Источник НБК на эту дату</a>. Курс указан за количество единиц в поле «Кратность».</p>
    <button type="button" className="secondary" disabled={busy || loading} onClick={onRefresh}>Загрузить курсы НБК</button><p className="muted">Эта кнопка заменит ручные курсы официальными. Автоматическая загрузка сохраняет ручные правки на ту же дату.</p>
  </aside>;
}
