import {useRef,useState} from 'react';
import {DocumentKindSelector,type DocumentKind} from './DocumentKindSelector';
import type {Session} from './types';
const statuses:Record<string,string>={pending:'Готов к распознаванию',recognizing:'Распознаётся',recognized:'Распознан',error:'Ошибка распознавания'};
const size=(bytes:number)=>bytes>=1048576?`${(bytes/1048576).toFixed(1)} МБ`:`${Math.max(1,Math.round(bytes/1024))} КБ`;
type Props={session:Session;busy:boolean;errors:string[];onUpload:(files:File[],groupId:string)=>void;onRemove:(id:string)=>void;onRecognize:()=>void;onGroup:(body:Record<string,unknown>)=>Promise<void>;onAssign:(fileId:string,groupId:string)=>Promise<void>};
export function UploadPage({session,busy,errors,onUpload,onRemove,onRecognize,onGroup,onAssign}:Props) {
  const [editingGroup,setEditingGroup]=useState<string|null>(null);
  const processing=session.phase==='recognizing'&&session.jobs.some(j=>['queued','running'].includes(j.status));const disabled=busy||processing;const groups=session.uploadGroups??[];
  const done=session.documents.filter(d=>['recognized','error'].includes(d.status)).length;
  const fileRow=(document:Session['documents'][number])=><div className="file-row" key={document.id}><div><span className="file-name">{document.name}</span><span className="file-meta">{size(document.size)} · {document.pages} стр./лист. · {statuses[document.status]??document.status}</span>{document.error&&<span className="field-error">{document.error.message}</span>}</div><button className="icon-button" disabled={disabled} aria-label={`Удалить ${document.name}`} onClick={()=>onRemove(document.id)}>×</button></div>;
  return <section className="upload-page" aria-labelledby="upload-heading"><div className="hero"><h1 id="upload-heading">Подготовьте декларацию ИМ 40</h1></div>
    <DocumentKindSelector busy={disabled} onAdd={(kind:DocumentKind)=>onGroup({code:kind.code,version:kind.version})}/>
    <p className="file-hint">PDF, JPG, PNG, XLSX · до {size(session.limits.max_file_bytes)} на файл · до {session.limits.max_files} файлов</p>
    {errors.length>0&&<div className="notice error" role="alert">{errors.map((e,i)=><p key={i}>{e}</p>)}</div>}
    <div className="upload-groups">{groups.map(group=>{const files=session.documents.filter(d=>d.groupId===group.id);return <article className="upload-group" key={group.id}><div className="upload-group-heading"><strong>{group.code} — {group.name}</strong><AttachFiles groupId={group.id} disabled={disabled} onUpload={onUpload}/><button className="text-button" disabled={disabled} onClick={()=>setEditingGroup(editingGroup===group.id?null:group.id)}>Изменить вид</button><button className="icon-button" disabled={disabled||files.length>0} aria-label={`Удалить вид ${group.code}`} onClick={()=>void onGroup({action:'remove',id:group.id}).catch(()=>undefined)}>×</button></div>{editingGroup===group.id&&<DocumentKindSelector label={`Новый вид ${group.code}`} actionLabel="Применить" busy={disabled} onAdd={kind=>onGroup({action:'type',id:group.id,code:kind.code,version:kind.version}).then(()=>setEditingGroup(null))}/>}<div className="file-list">{files.map(fileRow)}</div></article>;})}</div>
    {session.documents.some(d=>!d.groupId)&&<section className="untyped-files"><h2>Назначьте вид ранее загруженным файлам</h2>{session.documents.filter(d=>!d.groupId).map(document=><div key={document.id}>{fileRow(document)}<select aria-label={`Вид файла ${document.name}`} disabled={disabled} value="" onChange={e=>{if(e.target.value)void onAssign(document.id,e.target.value);}}><option value="">Выберите добавленный вид</option>{groups.map(g=><option key={g.id} value={g.id}>{g.code} — {g.name}</option>)}</select></div>)}</section>}
    {session.documents.length>0&&<div className="upload-actions"><span className="muted">Файлов: {session.documents.length}</span><button className="primary" disabled={disabled} onClick={onRecognize}>{processing?'Распознавание…':'Распознать документы'}</button></div>}
    {processing&&<div className="notice progress" role="status"><span className="spinner"/>{done<session.documents.length?`Распознаём документы. Завершено: ${done} из ${session.documents.length}.`:'Сопоставляем данные с графами декларации…'}</div>}
    {session.error&&<div className="notice error" role="alert">{session.error.message}</div>}
  </section>;
}
function AttachFiles({groupId,disabled,onUpload}:{groupId:string;disabled:boolean;onUpload:Props['onUpload']}) {
  const input=useRef<HTMLInputElement>(null);
  return <><button className="secondary attach-files" disabled={disabled} onClick={()=>input.current?.click()}>Прикрепить файлы</button><input className="visually-hidden" ref={input} aria-label={`Файлы ${groupId}`} type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.xlsx" disabled={disabled} onChange={e=>{onUpload(Array.from(e.target.files??[]),groupId);e.target.value='';}}/></>;
}
