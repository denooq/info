import {DocumentKindSelector} from './DocumentKindSelector';
import {FieldRow,required} from './FieldRow';
import {requiredDocumentRows} from './RequiredDocuments';
import type {Entity,FieldChange,Registry,Session} from './types';

export function DocumentRecords({session,registry,busy,onChange,onEntity}:{session:Session;registry:Registry;busy:boolean;onChange:(c:FieldChange)=>Promise<void>;onEntity:(b:Record<string,unknown>)=>Promise<void>}) {
  const rows=requiredDocumentRows(session,registry).map(row=>({...row,_recordPlaceholder:row._requiredPlaceholder} as Entity));
  for(const group of session.uploadGroups??[]) {
    if(!session.documents.some(d=>d.groupId===group.id)||rows.some(r=>r.kind===group.code))continue;
    const row:Entity={id:`document-proposed-${group.code}`,_recordPlaceholder:true,_displayKey:`proposed-${group.code}`,kind:group.code,name:group.name,dateRequired:true,goodsIds:session.draft.goods.length===1?[session.draft.goods[0].id]:[],documentIds:session.documents.filter(d=>d.groupId===group.id).map(d=>d.id)};
    rows.push(row);
  }
  return <div className="document-records">{rows.map(row=><article className="document-record" key={String(row._displayKey??row.id)} data-document-kind={String(row.kind??'')}><div className="entity-heading"><strong>{String(row.kind??'')} — {String(row.name??registry.catalogs.lists.documentKinds.find(k=>k.code===row.kind)?.label??'Документ')}</strong>{!row._recordPlaceholder&&<button className="text-button" disabled={busy} onClick={()=>void onEntity({scope:'document',action:'remove',id:row.id})}>Удалить</button>}</div><div className="graph-fields-grid">{registry.fields.fields.filter(f=>f.scope==='document'&&f.key!=='dateRequired'&&(f.key==='date'||typeof f.requiredWhen==='boolean'||required(f.requiredWhen,row))).map(f=><FieldRow key={f.id} field={f} row={row} entityId={row.id} session={session} registry={registry} busy={busy} onChange={c=>onChange(row._recordPlaceholder?{...c,recordKind:String(row.kind)}:c)}/>)}</div></article>)}<DocumentKindSelector label="Добавить представленный документ" busy={busy} onAdd={kind=>onEntity({scope:'document',action:'add',kind:kind.code,version:kind.version})}/></div>;
}
