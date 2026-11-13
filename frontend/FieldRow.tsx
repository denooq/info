import { useEffect, useState } from 'react';
import type { Field, FieldChange, Registry, Session, Value } from './types';

export function required(condition: Field['requiredWhen'], row: Record<string, unknown>): boolean {
  if (typeof condition === 'boolean') return condition;
  if (condition.op === 'eq') return (row[condition.field!] ?? null) === condition.value;
  if (condition.op === 'all') return condition.args!.every(c => required(c, row));
  if (condition.op === 'any') return condition.args!.some(c => required(c, row));
  if (condition.op === 'not') return !required(condition.arg!, row);
  return false;
}
export const addressOf = (field: Field, id: string | null) => `${field.scope}/${id ?? '_'}/${field.key}`;
export const domId = (address: string) => `field-${address.replaceAll('/', '-')}`;
const display = (value: Value) => Array.isArray(value) ? value.join('; ') : value === null ? '' : String(value);
const roles: Record<string,string> = { seller:'Продавец',buyer:'Покупатель',consignor:'Отправитель',consignee:'Получатель',declarant:'Декларант',financial:'Лицо, ответственное за финансовое урегулирование',arrival:'При прибытии',border:'На границе' };
export { roles };

export function FieldRow({ field, row, entityId, session, registry, busy, onChange }: { field: Field; row: Record<string, unknown>; entityId: string | null; session: Session; registry: Registry; busy: boolean; onChange: (change: FieldChange) => Promise<void> }) {
  const value = (row[field.key] ?? null) as Value;
  const address = addressOf(field, entityId); const meta = session.draft.fieldStates[address];
  const [local, setLocal] = useState(display(value)); const [error, setError] = useState('');
  useEffect(() => { setLocal(display(value)); setError(''); }, [value, session.dataRevision]);
  const active = required(field.requiredWhen, row);
  const candidates = Object.values(session.candidates).filter(c => c.address === address);
  const errors = session.validation.errors.filter(e => e.address === address);
  const fixed = Object.hasOwn(field, 'fixedValue') || (field.scope === 'party' && field.key === 'role') || (field.scope === 'transport' && field.key === 'stage') || field.id === 'document.dateRequired';
  async function save(next: Value) {
    setError(''); try { await onChange({ fieldId: field.id, entityId, value: next }); } catch (e) { setError((e as Error).message); }
  }
  function blur() {
    if (local === display(value)) return;
    let next: Value = local.trim() || null;
    if (field.type.endsWith('-list')) next = local.split(/[;\n]+/).map(s=>s.trim()).filter(Boolean);
    if (field.type === 'integer' && next !== null) { if (!/^\d+$/.test(local)) { setError('Введите целое неотрицательное число.'); return; } next = Number(local); }
    void save(next);
  }
  const catalog = registry.catalogs.fieldCatalogs[field.id];
  let options = catalog ? registry.catalogs.lists[catalog].map(e => ({ id:e.code,label:`${e.code} — ${e.label}` })) : [];
  let references: { id:string; label:string }[] | null = null;
  if (field.key === 'goodsIds') references = session.draft.goods.map((g,i)=>({id:g.id,label:`Товар ${i+1}: ${g.description || 'без названия'}`}));
  if (field.key === 'packageIds' || field.key === 'shipmentPackageIds') references = session.draft.packages.map(p=>({id:p.id,label:`${p.label || 'Группа мест'} (${p.quantity ?? '?'})`}));
  if (field.key === 'valuationDocumentIds') references = session.draft.documents.map(d=>({id:d.id,label:`${d.name || 'Документ'} №${d.number || '—'}`}));
  if (field.key === 'documentIds') references = session.documents.map(d=>({id:d.id,label:d.name}));
  if (field.key === 'sourceLineIds') references = Object.values(session.draft.sourceLines).map(l=>({id:l.id,label:l.quote || l.id}));
  if (field.key === 'evidenceIds') references = Object.entries(session.evidence).map(([id,e])=>({id,label:`${e.documentName}: ${e.quote}`}));
  const id = domId(address);
  const controls = references ? <div className="reference-options" id={id} tabIndex={-1}>{references.length === 0 ? <span className="muted">Сначала добавьте строки соответствующего раздела.</span> : references.map(option=><label key={option.id}><input type="checkbox" disabled={busy} checked={Array.isArray(value) && value.includes(option.id)} onChange={e=>void save(e.target.checked ? [...(Array.isArray(value)?value:[]),option.id] : (Array.isArray(value)?value:[]).filter(v=>v!==option.id))}/><span>{option.label}</span></label>)}</div>
    : field.type === 'boolean' ? <select id={id} disabled={busy || fixed} value={value === null?'':value?'yes':'no'} onChange={e=>void save(e.target.value===''?null:e.target.value==='yes')}><option value="">Выберите ответ</option><option value="yes">Да</option><option value="no">Нет</option></select>
    : field.type === 'enum' ? <select id={id} value={local} disabled={busy || fixed} onChange={e=>void save(e.target.value || null)}><option value="">Выберите значение</option>{field.values?.map(v=><option value={v} key={v}>{roles[v] || enumLabels[v] || v}</option>)}</select>
    : <><input id={id} disabled={busy} readOnly={fixed} type={field.type==='date'?'date':'text'} inputMode={['decimal','integer'].includes(field.type)?'decimal':undefined} list={options.length ? `${id}-list`:undefined} value={local} onChange={e=>setLocal(e.target.value)} onBlur={blur} aria-invalid={!!error || errors.length>0} aria-describedby={errors.length || error ? `${id}-error`:undefined} />{options.length>0 && <datalist id={`${id}-list`}>{options.map(o=><option key={o.id} value={o.id}>{o.label}</option>)}</datalist>}</>;
  return <div className={`field-row ${errors.length || error ? 'has-error':''}`} data-address={address}>
    <label className="field-label" htmlFor={id}>{field.label}{active && <span className="required" aria-label="обязательное поле"> *</span>}<span className="field-location">{field.dtGraphs.length>0?`ДТ ${field.dtGraphs.join(', ')}`:''}{field.dtsFields?.length?` · ДТС ${field.dtsFields.join(', ')}`:''}</span></label>
    <div className="field-control">{controls}<span className="value-status">{meta?.status==='manual'?'Введено вручную':meta?.status==='selected'?'Выбран источник':meta?.status==='auto'?'Из документа':fixed?'Задано профилем':value===null?'Не заполнено':''}</span>{(errors.length>0 || error) && <span className="field-error" id={`${id}-error`}>{error || errors.map(e=>e.message).join(' ')}</span>}</div>
    <div className="candidate-list">{candidates.map(c=>{
      const sources=c.evidenceIds.map(id=>session.evidence[id]).filter(Boolean);
      const tooltip=sources.map(e=>`${e.documentName}${e.page?`, стр. ${e.page}`:`, ${e.sheet}, ${e.cell}`}\n${e.quote}`).join('\n\n');
      return <span className="candidate-wrap" key={c.id}><button type="button" className={`candidate ${meta?.candidateId===c.id?'selected':''}`} disabled={busy} aria-pressed={meta?.candidateId===c.id} aria-describedby={`source-${c.id}`} onClick={()=>void onChange({fieldId:field.id,entityId,candidateId:c.id}).catch(e=>setError(e.message))}>{display(c.value)}{c.unit==='kg'?' кг':''}</button><span role="tooltip" id={`source-${c.id}`} className="source-tooltip">{tooltip}{c.issues.length>0 && `\nТребует проверки: ${c.issues.join(' ')}`}</span></span>;
    })}</div>
  </div>;
}
const enumLabels: Record<string,string> = { addition:'Начисление',deduction:'Вычет',indirect:'Косвенный платёж',invoiceValue:'По стоимости товаров',netMass:'По массе нетто',grossMass:'По массе брутто',equal:'Поровну',manual:'Вручную',freight:'Перевозка до места ввоза',insurance:'Страхование',loading:'Погрузка',sellingCommission:'Комиссионные продавцу',containersCost:'Тара',packingCost:'Упаковка',buyerMaterials:'Материалы покупателя',buyerTools:'Инструменты покупателя',buyerConsumables:'Расходные материалы покупателя',buyerEngineering:'Проектирование',royalties:'Лицензионные платежи',resaleProceeds:'Доход от перепродажи',postImportWork:'Работы после ввоза',inlandTransport:'Перевозка после ввоза',importTaxes:'Включённые налоги',indirectPayments:'Косвенные платежи' };
