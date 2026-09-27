export function friendlyError(message: string | null | undefined): string {
  if (!message) return '작업 중 오류가 발생했습니다.'
  if (message.includes('billing'))
    return 'AI 공급자 계정의 크레딧(잔액)이 부족합니다. 관리 화면에서 확인해 주세요.'
  if (message.includes('not_configured')) return 'AI API 키가 없습니다. 관리 화면에서 키를 넣어 주세요.'
  if (message.includes('auth')) return 'AI API 키가 올바르지 않습니다. 관리 화면에서 확인해 주세요.'
  return message
}
