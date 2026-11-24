import {FieldRow} from './FieldRow';
import type {Entity,FieldChange,Registry,Session} from './types';

export function DocumentRecords({session,registry,busy,goodsId,onChange,onEntity}:{session:Session;registry:Registry;busy:boolean;goodsId:string|null;onChange:(c:FieldChange)=>Promise<void>;onEntity:(b:Record<string,unknown>)=>Promise<void>}) {
  const files=session.documents.filter(file=>file.groupId&&session.uploadGroups?.some(group=>group.id===file.groupId));
  const records=session.draft.documents.filter(record=>{const ids=Array.isArray(record.goodsIds)?record.goodsIds as string[]:[];return ids.length===0||(goodsId!==null&&ids.includes(goodsId));});
  const linked=new Set(records.flatMap(record=>record.documentIds??[]));
  const rows:[Entity,string|null][] = records.map(record=>[record,null]);
  for(const file of files)if(!linked.has(file.id)) {
    const group=session.uploadGroups?.find(item=>item.id===file.groupId);if(!group)continue;
    const name=registry.catalogs.lists.documentKinds.find(item=>item.code===group.code)?.label??group.name;
    const row={id:`unmapped-${file.id}`,kind:group.code,name,dateRequired:true,number:null,date:null,presentationCode:null,documentIds:[file.id],goodsIds:goodsId?[goodsId]:[],_recordPlaceholder:true,_recordFileId:file.id} as unknown as Entity;
    rows.push([row,file.id]);
  }
  const fields=registry.fields.fields.filter(field=>field.scope==='document'&&!['kind','name','dateRequired','goodsIds','documentIds','valuationMethod'].includes(field.key));
  return <div className="document-records">{rows.length===0&&<p className="muted">Прикреплённые документы не распознаны как записи графы 44.</p>}{rows.map(([row,fileId])=>{
    const relatedFiles=session.documents.filter(file=>(row.documentIds as string[]|undefined)?.includes(file.id));
    const placeholder=row._recordPlaceholder===true;
    return <article className="document-record" key={row.id} data-document-kind={String(row.kind??'')}><div className="entity-heading"><strong>{String(row.kind??'')} — {String(row.name??'Документ')}</strong><span>{relatedFiles.map(file=>file.name).join(', ')}</span>{!placeholder&&<button className="text-button" disabled={busy} onClick={()=>void onEntity({scope:'document',action:'remove',id:row.id})}>Удалить запись</button>}</div><div className="graph-fields-grid">{fields.map(field=><FieldRow key={field.id} field={field} row={row} entityId={row.id} session={session} registry={registry} busy={busy} onChange={change=>onChange(placeholder?{...change,recordKind:String(row.kind),recordFileId:fileId??undefined}:{...change,recordKind:String(row.kind),recordId:row.id})}/>)}</div></article>;
  })}</div>;
}
