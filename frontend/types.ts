export type Value = string | number | boolean | null | string[];
export type Entity = { id: string; [key: string]: unknown };
export type FieldState = { status: string; candidateId: string | null; evidenceIds: string[]; conflict?: boolean; needsReview?: boolean; tnved?: { status: string; message: string; total?: number } };
export type Candidate = { id: string; address: string; fieldId: string; entityId: string | null; value: Value; unit: string | null; valid: boolean; issues: string[]; evidenceIds: string[] };
export type Evidence = { documentId: string; documentName: string; page?: number; sheet?: string; cell?: string; quote: string; kind?: string; url?: string };
export type Session = {
  createdAt: number; expiresAt: number; dataRevision: number; phase: string; csrfToken: string;
  draft: { declaration: Record<string, Value>; keden: Record<string, Value>; parties: Entity[]; goods: Entity[]; transports: Entity[]; documents: Entity[]; costs: Entity[]; rates: Entity[]; payments: Entity[]; sourceLines: Record<string, { id: string; quote?: string; origin: string; evidenceId?: string }>; packages: Entity[]; fieldStates: Record<string, FieldState> };
  documents: { id: string; name: string; mime: string; size: number; pages: number; status: string; error?: { code: string; message: string } | null }[];
  jobs: { id: string; kind: string; documentId?: string; status: string; error?: { code: string; message: string } | null }[];
  candidates: Record<string, Candidate>; evidence: Record<string, Evidence>; mappingIssues?: { message: string }[];
  error: { code: string; message: string } | null; ready: boolean; calculation: unknown;
  validation: { valid: boolean; count: number; errors: { address: string; code: string; message: string }[] };
  limits: { session_ttl: number; max_files: number; max_file_bytes: number; max_session_bytes: number; max_pages: number };
};
export type Condition = boolean | { op: string; field?: string; value?: unknown; args?: Condition[]; arg?: Condition };
export type Field = { id: string; key: string; scope: string; label: string; type: string; acquisition: string[]; requiredWhen: Condition; validWhen: { op: string; value?: unknown }[]; fixedValue?: Value; values?: string[]; unit?: string; section?: string; dtGraphs: string[]; dtsFields?: string[] };
export type Registry = { fields: { fields: Field[]; scopes: Record<string, { collection: string | null }> }; catalogs: { lists: Record<string, { code: string; label: string }[]>; fieldCatalogs: Record<string, string> } };
export type FieldChange = { fieldId: string; entityId: string | null; value?: Value; candidateId?: string };
