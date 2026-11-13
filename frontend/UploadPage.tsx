import { useRef, useState } from 'react';
import type { Session } from './types';
const statuses: Record<string, string> = { pending: 'Готов к распознаванию', recognizing: 'Распознаётся', recognized: 'Распознан', error: 'Требует проверки' };
const size = (bytes: number) => bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} МБ` : `${Math.max(1, Math.round(bytes / 1024))} КБ`;

export function UploadPage({ session, busy, errors, onUpload, onRemove, onRecognize }: { session: Session; busy: boolean; errors: string[]; onUpload: (files: File[]) => void; onRemove: (id: string) => void; onRecognize: () => void }) {
  const input = useRef<HTMLInputElement>(null);
  const [dragging, setDragging] = useState(false);
  const processing = session.phase === 'recognizing' && !session.error;
  const done = session.jobs.filter(job => job.status === 'done').length;
  return <section className="upload-page" aria-labelledby="upload-heading">
    <div className="hero"><h1 id="upload-heading">Подготовьте декларацию<br />ИМ 40 без регистрации</h1><p>Загрузите первичные документы.<br />Проверьте данные и получите<br />PDF декларации и XML ДТ и ДТС.</p></div>
    <div className={`drop-zone ${dragging ? 'is-dragging' : ''}`} onDragOver={event => { event.preventDefault(); setDragging(true); }} onDragLeave={() => setDragging(false)} onDrop={event => { event.preventDefault(); setDragging(false); if (!busy && !processing) onUpload(Array.from(event.dataTransfer.files)); }}>
      <svg className="document-icon" width="64" height="80" viewBox="0 0 64 80" fill="none" aria-hidden="true"><path d="M10 2h31l15 16v60H10V2Z" fill="white" stroke="currentColor" strokeWidth="1.5" /><path d="M41 2v17h15M21 44h25M21 50h25M21 56h25M21 62h17" stroke="currentColor" strokeWidth="1.5" /></svg>
      <p className="drop-title">Перетащите сюда документы</p><div className="or"><span />или<span /></div>
      <button type="button" className="primary" onClick={() => input.current?.click()} disabled={busy || processing}><svg width="18" height="20" viewBox="0 0 18 20" fill="none" aria-hidden="true"><path d="m6 10 6-6a3 3 0 0 1 4 4L8 16a4 4 0 0 1-6-6l8-8" stroke="currentColor" strokeWidth="1.7" /></svg>Выбрать документы</button>
      <input ref={input} className="visually-hidden" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.xlsx" aria-label="Прикрепить документы" disabled={busy || processing} onChange={event => { onUpload(Array.from(event.target.files ?? [])); event.target.value = ''; }} />
      <p className="file-hint">PDF, JPG, PNG, XLSX · до {size(session.limits.max_file_bytes)} на файл · до {session.limits.max_files} файлов</p>
    </div>
    {errors.length > 0 && <div className="notice error" role="alert">{errors.map((error, index) => <p key={index}>{error}</p>)}</div>}
    {session.documents.length > 0 && <><div className="file-list" aria-label="Добавленные документы">{session.documents.map(document => <div className="file-row" key={document.id}><div><span className="file-name">{document.name}</span><span className="file-meta">{size(document.size)} · {document.pages} стр./лист. · {statuses[document.status] ?? document.status}</span>{document.error && <span className="field-error">{document.error.message}</span>}</div><button className="icon-button" type="button" disabled={busy || processing} aria-label={`Удалить ${document.name}`} onClick={() => onRemove(document.id)}>×</button></div>)}</div><div className="upload-actions"><span className="muted">Документов: {session.documents.length}</span><button type="button" className="primary" disabled={busy || processing} onClick={onRecognize}>{busy ? 'Загрузка…' : processing ? 'Распознавание…' : session.error ? 'Повторить распознавание' : 'Распознать документы'}</button></div></>}
    {processing && <div className="notice progress" role="status"><span className="spinner" />Распознаём документы и сопоставляем данные. Завершено этапов: {done} из {session.jobs.length || '…'}.</div>}
    {session.error && <div className="notice error" role="alert">{session.error.message}</div>}
    <p className="session-note">Файлы хранятся во временной сессии и удаляются после её завершения.</p>
  </section>;
}
