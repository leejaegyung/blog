import { fetchCsrfCookie, http } from './http'

export type User = { id: number; name: string; username: string | null; email: string }

export type ProjectStatus = 'draft' | 'analyzing' | 'analyzed' | 'failed'

export type Project = {
  id: number
  keyword: string
  category: string | null
  status: ProjectStatus
  last_analyzed_at: string | null
  analysis_version: string | null
  // 사용자가 고친 해시태그(null이면 분석 추천을 쓴다)
  hashtags?: string[] | null
  reference_count?: number
  post_count?: number
  created_at: string
  updated_at: string
}

export type ProjectInput = { keyword: string; category?: string | null }

export type ParseStatus = 'pending' | 'needs_text' | 'parsed' | 'duplicate' | 'failed'

export type Reference = {
  id: number
  source_type: 'user_url' | 'user_text'
  source_url: string | null
  title: string | null
  author: string | null
  published_at: string | null
  parse_status: ParseStatus
  error_message: string | null
  char_count: number | null
  paragraph_count: number | null
  image_count: number | null
  heading_count: number | null
  collected_at: string | null
  created_at: string
}

type Spread = { median: number; p25: number; p75: number }
type ShareItem = { label: string; share: number }
type TermItem = { term: string; documents: number; share: number }

export type KeywordStats = {
  reference_count: number
  char_count: Spread
  heading_count: Spread
  paragraph_count: Spread
  photos: {
    count: Spread
    starts_with_photo_share: number
    intro_images: Spread
    max_group_size: Spread
    paragraphs_between_groups: Spread | null
    per_1000_chars: Spread
  }
  title: {
    length: Spread
    keyword_position: ShareItem[]
    with_brackets_share: number
    with_number_share: number
    with_question_share: number
  } | null
  keyword_in_first_paragraph_share: number
  slots: ShareItem[]
  topics: TermItem[]
  terms: TermItem[]
  opening_patterns: ShareItem[]
  intro_types: ShareItem[]
  ending_summary_share: number
  ending_recommendation_share: number
  ending_engagement_share: number
}

/** 검색 노출 가이드(참고 글 통계 + 네이버 공개 원칙, 코드 계산). 순위 보장이 아니다 */
export type GuideTarget = { key: string; label: string; target: string; basis: string; min: number | null; max: number | null }
export type HashtagSuggestion = { tag: string; source: 'keyword' | 'references' | 'ai'; share: number | null }
export type ExposureGuide = {
  version: string
  reference_count: number
  targets: GuideTarget[]
  principles: string[]
  hashtags: HashtagSuggestion[]
}

export type KeywordAnalysis = {
  id: number
  primary_intent: string | null
  intent_distribution: ShareItem[]
  must_answer: string[]
  recommended_outline: { heading: string; purpose: string; photo_hint: string }[]
  title_guidelines: string[]
  related_keywords: string[]
  writing_tips: string[]
  stats: KeywordStats | null
  guide: ExposureGuide | null
  insight_error: string | null
  prompt_version: string | null
  stale: boolean
  expires_at: string | null
  created_at: string
}

export type Tone = 'natural' | 'expert' | 'friendly' | 'clean'

export type PostStatus = 'draft' | 'planning' | 'planned' | 'generating' | 'review' | 'published' | 'failed'

export type Fact = { fact_key: string; fact_value: string }

export type PhotoVision = {
  type: string
  description: string
  usable: boolean
  quality_score: number
  suggested_section: string
  caption_hint: string
  privacy_flags: string[]
}

export type PostImage = {
  id: number
  url: string
  thumb_url: string
  original_name: string | null
  width: number | null
  height: number | null
  size_bytes: number | null
  taken_at: string | null
  sort_order: number
  caption: string | null
  vision?: PhotoVision | null
  vision_status?: 'pending' | 'done' | 'failed' | null
  vision_error?: string | null
}

export type PlanSection = {
  heading: string
  purpose: string | null
  key_points: string[]
  fact_keys: string[]
  image_ids: number[]
}

export type WritingPlan = {
  title_candidates: string[]
  search_intent: string
  outline: PlanSection[]
  keywords: { primary: string[]; secondary: string[] }
  required_fact_keys: string[]
  forbidden_claims: string[]
  unused_fact_keys: string[]
  unplaced_image_ids: number[]
  corrections: string[]
}

export type ContentBlock = {
  type: 'heading' | 'paragraph' | 'image' | 'list' | 'quote'
  text?: string | null
  image_id?: number | null
  items?: string[] | null
}

export type DraftWarning = {
  code: 'unsupported_specific' | 'fact_missing' | 'length' | 'image_unplaced' | 'image_invalid'
  message: string
}

export type QualityIssue = {
  suggested_fact_key?: string | null
  code: string
  severity: 'error' | 'warning' | 'info'
  message: string
  block_index: number | null
  excerpt: string | null
}

export type QualityReport = {
  version: string
  score: number
  parts: { key: string; label: string; score: number; max: number }[]
  issues: QualityIssue[]
  metrics: Record<string, number>
}

export type ExportResult = {
  html: string
  text: string
  tags: string[]
  photos: { number: number; image_id: number; filename: string; url: string }[]
  warnings: string[]
}

export type PipelineStep = 'vision' | 'analysis' | 'plan' | 'draft' | 'quality'

export type Post = {
  id: number
  keyword_project_id: number | null
  keyword?: string | null
  pipeline_status?: 'running' | 'done' | 'failed' | null
  pipeline_step?: PipelineStep | null
  pipeline_error?: string | null
  title: string | null
  tone: Tone | null
  target_length: number | null
  status: PostStatus
  published_url: string | null
  published_at: string | null
  plan: WritingPlan | null
  plan_error: string | null
  plan_stale: boolean | null
  content: { blocks: ContentBlock[]; tags: string[] } | null
  content_original: { blocks: ContentBlock[]; tags: string[] } | null
  // 이 키워드로 쓸 때 다는 해시태그(글 하나를 불러올 때만)
  recommended_hashtags?: string[]
  draft_meta: {
    char_count: number
    target_length: number
    keyword_count: number
    warnings: DraftWarning[]
  } | null
  draft_error: string | null
  quality: QualityReport | null
  quality_checked_at: string | null
  quality_stale: boolean | null
  facts?: (Fact & { id: number })[]
  images?: PostImage[]
  image_count?: number
  fact_count?: number
  created_at: string
  updated_at: string
}

export type PostInput = {
  title?: string | null
  tone?: Tone | null
  target_length?: number | null
  facts?: Fact[]
  content?: { blocks: ContentBlock[]; tags: string[] }
}

export type RewriteInstruction = 'shorter' | 'longer' | 'natural' | 'rewrite'

type Wrapped<T> = { data: T }

export const authApi = {
  async login(login: string, password: string, remember: boolean) {
    await fetchCsrfCookie()
    const { data } = await http.post<Wrapped<User>>('/login', { login, password, remember })
    return data.data
  },
  async logout() {
    await http.post('/logout')
  },
  async me() {
    const { data } = await http.get<Wrapped<User> & { meta?: { auto_login?: boolean } }>('/user')
    return { user: data.data, autoLogin: data.meta?.auto_login ?? false }
  },
}

export const projectApi = {
  async list() {
    const { data } = await http.get<Wrapped<Project[]>>('/projects')
    return data.data
  },
  async get(id: number) {
    const { data } = await http.get<Wrapped<Project>>(`/projects/${id}`)
    return data.data
  },
  async create(input: ProjectInput) {
    const { data } = await http.post<Wrapped<Project>>('/projects', input)
    return data.data
  },
  async update(id: number, input: Partial<ProjectInput>) {
    const { data } = await http.patch<Wrapped<Project>>(`/projects/${id}`, input)
    return data.data
  },
  async remove(id: number) {
    await http.delete(`/projects/${id}`)
  },
  /** null이면 분석 추천으로 되돌린다 */
  async saveHashtags(id: number, hashtags: string[] | null) {
    const { data } = await http.put<Wrapped<Project>>(`/projects/${id}/hashtags`, { hashtags })
    return data.data
  },
}

export const postApi = {
  async list(projectId?: number) {
    const { data } = await http.get<Wrapped<Post[]>>('/posts', {
      params: projectId ? { project_id: projectId } : {},
    })
    return data.data
  },
  async get(id: number) {
    const { data } = await http.get<Wrapped<Post>>(`/posts/${id}`)
    return data.data
  },
  /** 글과 사진을 지운다(되돌릴 수 없다) */
  async remove(id: number) {
    await http.delete(`/posts/${id}`)
  },
  async start(keyword: string, category?: string) {
    const { data } = await http.post<Wrapped<Post>>('/posts/start', {
      keyword,
      category: category || null,
    })
    return data.data
  },
  /** until='plan': 사진 분석→키워드 분석→계획까지만 */
  async autopilot(id: number, until: 'plan' | 'draft' = 'draft') {
    const { data } = await http.post<Wrapped<Post>>(`/posts/${id}/autopilot`, { until })
    return data.data
  },
  async create(projectId: number, input: PostInput = {}) {
    const { data } = await http.post<Wrapped<Post>>('/posts', {
      keyword_project_id: projectId,
      ...input,
    })
    return data.data
  },
  async update(id: number, input: PostInput) {
    const { data } = await http.patch<Wrapped<Post>>(`/posts/${id}`, input)
    return data.data
  },
  async plan(id: number) {
    const { data } = await http.post<Wrapped<Post>>(`/posts/${id}/plan`)
    return data.data
  },
  async rewrite(
    id: number,
    input: { text: string; instruction: RewriteInstruction; before?: string; after?: string },
  ) {
    const { data } = await http.post<{ text: string; warnings: DraftWarning[] }>(
      `/posts/${id}/rewrite`,
      input,
    )
    return data
  },
  /** record=false: 미리 준비만 하고 올리기 기록은 남기지 않는다 */
  async exportPost(id: number, record = true) {
    const { data } = await http.post<Wrapped<ExportResult>>(`/posts/${id}/export`, { record })
    return data.data
  },
  photosZipUrl(id: number) {
    return `/api/posts/${id}/export/photos.zip`
  },
  async publish(id: number, publishedUrl: string) {
    const { data } = await http.post<Wrapped<Post>>(`/posts/${id}/publish`, {
      published_url: publishedUrl,
    })
    return data.data
  },
  async qualityCheck(id: number) {
    const { data } = await http.post<Wrapped<Post>>(`/posts/${id}/quality-check`)
    return data.data
  },
  async generate(id: number) {
    const { data } = await http.post<Wrapped<Post>>(`/posts/${id}/generate`)
    return data.data
  },
  async savePlan(id: number, input: { title?: string | null; outline: PlanSection[] }) {
    const { data } = await http.put<Wrapped<Post>>(`/posts/${id}/plan`, input)
    return data.data
  },
}

export const imageApi = {
  async upload(
    postId: number,
    file: File,
    options: { stripExif: boolean; onProgress?: (ratio: number) => void },
  ) {
    const form = new FormData()
    form.append('image', file)
    form.append('strip_exif', options.stripExif ? '1' : '0')
    const { data } = await http.post<Wrapped<PostImage>>(`/posts/${postId}/images`, form, {
      onUploadProgress: (e) => options.onProgress?.(e.total ? e.loaded / e.total : 0),
    })
    return data.data
  },
  async analyze(postId: number, force = false) {
    const { data } = await http.post<{ data: PostImage[]; queued: number }>(
      `/posts/${postId}/images/analyze`,
      { force },
    )
    return data
  },
  async remove(postId: number, imageId: number) {
    await http.delete(`/posts/${postId}/images/${imageId}`)
  },
  async reorder(postId: number, ids: number[]) {
    const { data } = await http.patch<Wrapped<PostImage[]>>(`/posts/${postId}/images/order`, {
      ids,
    })
    return data.data
  },
}

export const referenceApi = {
  async list(projectId: number) {
    const { data } = await http.get<Wrapped<Reference[]>>(`/projects/${projectId}/references`)
    return data.data
  },
  async addUrls(projectId: number, urls: string[]) {
    const { data } = await http.post<{ data: Reference[]; skipped: { url: string; reason: string }[] }>(
      `/projects/${projectId}/references`,
      { urls },
    )
    return data
  },
  async addText(projectId: number, text: string, title?: string) {
    const { data } = await http.post<{ data: Reference[] }>(`/projects/${projectId}/references`, {
      text,
      title: title || null,
    })
    return data.data[0]!
  },
  async pasteText(id: number, text: string, title?: string) {
    const { data } = await http.post<Wrapped<Reference>>(`/references/${id}/text`, {
      text,
      title: title || null,
    })
    return data.data
  },
  async reparse(id: number) {
    const { data } = await http.post<Wrapped<Reference>>(`/references/${id}/parse`)
    return data.data
  },
  async remove(id: number) {
    await http.delete(`/references/${id}`)
  },
}

export const analysisApi = {
  async get(projectId: number) {
    const { data } = await http.get<{ data: KeywordAnalysis | null; status: ProjectStatus }>(
      `/projects/${projectId}/analysis`,
    )
    return data
  },
  async analyze(projectId: number, force = false) {
    const { data } = await http.post<{
      data?: KeywordAnalysis
      status: ProjectStatus
      cached: boolean
    }>(`/projects/${projectId}/analyze`, { force })
    return data
  },
}

export type UsageGroup = {
  purpose: string
  provider: string
  model: string
  calls: number
  success: number
  input_tokens: number
  output_tokens: number
  avg_latency_ms: number | null
}

export type Usage = {
  days: number
  kpi: {
    posts: number
    drafted: number
    published: number
    llm_calls: number
    llm_failure_rate: number
    tokens: number
    avg_draft_latency_ms: number
  }
  by_group: UsageGroup[]
  daily: { day: string; calls: number; failed: number; tokens: number }[]
}

export type FailedJob = { uuid: string; job: string; queue: string; error: string; failed_at: string }

export const adminApi = {
  async usage(days: number) {
    const { data } = await http.get<Wrapped<Usage>>('/admin/usage', { params: { days } })
    return data.data
  },
  async failedJobs() {
    const { data } = await http.get<Wrapped<FailedJob[]>>('/admin/failed-jobs')
    return data.data
  },
  async retry(uuid: string) {
    await http.post(`/admin/failed-jobs/${uuid}/retry`)
  },
  async forget(uuid: string) {
    await http.delete(`/admin/failed-jobs/${uuid}`)
  },
}

export type LlmProvider = {
  provider: 'anthropic' | 'openai'
  label: string
  models: string[]
  key_prefix: string
  console: string
  source: 'admin' | 'env' | 'none'
  masked_key: string | null
  updated_at: string | null
}

export type LlmTarget = { provider: 'anthropic' | 'openai'; model: string }

export type LlmState = {
  providers: LlmProvider[]
  route: LlmTarget[]
  route_source: 'admin' | 'env'
}

export type LlmTestResult = {
  ok: boolean
  reply: string | null
  attempts: {
    provider: string
    model: string
    status: string
    error_kind: string | null
    // 실패 시 공급자가 보낸 오류 문장(키는 가림)과 키가 속한 계정(조직·프로젝트 ID)
    error_message?: string | null
    account?: string | null
    latency_ms: number
  }[]
}

export const llmApi = {
  async get() {
    const { data } = await http.get<Wrapped<LlmState>>('/admin/llm')
    return data.data
  },
  async saveKey(provider: string, apiKey: string) {
    const { data } = await http.put<Wrapped<LlmState>>(`/admin/llm/keys/${provider}`, { api_key: apiKey })
    return data.data
  },
  async deleteKey(provider: string) {
    const { data } = await http.delete<Wrapped<LlmState>>(`/admin/llm/keys/${provider}`)
    return data.data
  },
  async saveRoute(route: LlmTarget[]) {
    const { data } = await http.put<Wrapped<LlmState>>('/admin/llm/route', { route })
    return data.data
  },
  async resetRoute() {
    const { data } = await http.put<Wrapped<LlmState>>('/admin/llm/route', { reset: true })
    return data.data
  },
  async test(target?: string) {
    const { data } = await http.post<Wrapped<LlmTestResult>>('/admin/llm/test', { target })
    return data.data
  },
}
