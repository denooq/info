export class ApiError extends Error {
  constructor(public code: string, message: string, public status: number, public details: unknown) { super(message); }
}
export async function request<T>(path: string, options: RequestInit = {}, csrf?: string): Promise<T> {
  const headers = new Headers(options.headers);
  if (csrf) headers.set('X-CSRF-Token', csrf);
  if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
  const response = await fetch(path, { ...options, headers, credentials: 'same-origin' });
  const payload = await response.json();
  if (!response.ok) {
    const code=payload.error?.code ?? 'REQUEST_FAILED';
    const message=code==='SESSION_REQUIRED'?'Браузер не передал cookie текущей сессии. Проверьте соединение с ней; введённые данные остаются на странице.':code==='SESSION_EXPIRED'?'Срок бездействия временной сессии истёк. Несохранённый ввод оставлен на странице.':payload.error?.message ?? 'Не удалось выполнить действие.';
    throw new ApiError(code,message,response.status,payload.error?.details);
  }
  return payload as T;
}
