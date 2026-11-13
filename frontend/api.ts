export class ApiError extends Error {
  constructor(public code: string, message: string, public status: number, public details: unknown) { super(message); }
}
export async function request<T>(path: string, options: RequestInit = {}, csrf?: string): Promise<T> {
  const headers = new Headers(options.headers);
  if (csrf) headers.set('X-CSRF-Token', csrf);
  if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
  const response = await fetch(path, { ...options, headers, credentials: 'same-origin' });
  const payload = await response.json();
  if (!response.ok) throw new ApiError(payload.error?.code ?? 'REQUEST_FAILED', payload.error?.message ?? 'Не удалось выполнить действие.', response.status, payload.error?.details);
  return payload as T;
}
