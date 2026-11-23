import type {Session} from './types';

// Identity includes the session and selected types, not transient job progress.
export function recognitionKey(session:Session):string {
  return JSON.stringify([session.csrfToken,session.createdAt,session.documents.map(d=>[d.id,d.documentKind??null,d.classifierVersion??null]).sort((a,b)=>String(a[0]).localeCompare(String(b[0])))]);
}

export function recognitionProgress(session:Session) {
  const activeJobs=session.jobs.filter(j=>['ocr','mapping'].includes(j.kind)&&['queued','running'].includes(j.status));
  const activeFiles=new Set(activeJobs.filter(j=>j.kind==='ocr').map(j=>j.documentId));
  const total=session.documents.length;
  const recognized=session.documents.filter(d=>d.status==='recognized'&&!activeFiles.has(d.id)).length;
  const failed=session.documents.filter(d=>d.status==='error'&&!activeFiles.has(d.id)).length;
  return {active:session.phase==='recognizing',total,recognized,failed,mapping:total>0&&recognized+failed===total,terminal:session.phase==='reviewing'&&activeJobs.length===0};
}
