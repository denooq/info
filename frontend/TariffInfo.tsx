import type { Entity, Field } from './types';
import { required } from './FieldRow';

type Tariff = {code:string; sourceUrl:string; sourcePage:number; units:{additional_raw:string;qualifier:string|null}; duty:{raw_text:string}; notes:{id:string;text:string;status_hint:string}[]; documents:Record<string,{id:string;title:string;url:string}>};
export function tariffFieldVisible(field:Field,row:Record<string,unknown>):boolean {
  if(field.scope!=='goods') return true;
  if(['tariffFromCatalogue','tariffConfirmed'].includes(field.key)) return !!row._tariff && (field.key!=='tariffConfirmed' || row.tariffFromCatalogue===true);
  if(field.key==='secondaryAdditionalUnitRequired') return row.secondaryAdditionalUnitRequired===true;
  if(['dutyRate','dutyAlternativeRate','dutySpecificRate','dutyCurrency','dutyPerQuantity','dutyUnitSymbol','dutyQuantityBasis','dutyQuantity','secondaryAdditionalUnit','secondaryAdditionalQuantity'].includes(field.key)) return required(field.requiredWhen,row);
  if(field.key==='dutyUnitCode') return !!row.dutyUnitCode && row.dutyFormula!=='advalorem';
  return true;
}
export function TariffInfo({row}:{row?:Entity}) {
  const tariff=row?._tariff as Tariff|undefined;
  if(!tariff || !row) return null;
  return <aside className="tariff-info" aria-label={`Тариф ${tariff.code}`}><p><strong>Базовая ЕТТ: {tariff.duty.raw_text}</strong> · <a href={tariff.sourceUrl} target="_blank" rel="noreferrer">Источник, стр. {tariff.sourcePage}</a></p>
    <p>Дополнительные единицы: {tariff.units.additional_raw}{tariff.units.qualifier?` (${tariff.units.qualifier})`:''}.</p>
    {row.tariffFromCatalogue===true && <p className={row.tariffConfirmed===true?'':'candidate-warning'}>{row.tariffConfirmed===true?'Применимость ставки подтверждена пользователем.':'Подтвердите применимость ставки ниже: дата, происхождение, льготы, исключения Казахстана и сноски. Справочник содержит базовую ставку; действующая ставка в нём не определена.'}</p>}
    {tariff.notes.length>0 && <details><summary>Сноски к ставке ({tariff.notes.length})</summary>{tariff.notes.map(note=><p key={note.id}><strong>{note.id}</strong> · {note.text}{note.status_hint==='repealed'?' (в источнике отмечено как утратившее силу)':''}</p>)}<p>Упомянутые в сносках даты не применяются автоматически.</p></details>}
    <details><summary>Правила и источники</summary>{Object.values(tariff.documents).map(doc=><p key={doc.id}><a href={doc.url} target="_blank" rel="noreferrer">{doc.title}</a></p>)}</details>
    {(row.dutyFormula!=='advalorem' || row.additionalUnitRequired===true || row.secondaryAdditionalUnitRequired===true) && <p className="candidate-warning">Расчёт доступен после заполнения данных. Создание комплекта файлов для этой формулы или дополнительных единиц пока недоступно: их нет в согласованном образце XML.</p>}
  </aside>;
}
