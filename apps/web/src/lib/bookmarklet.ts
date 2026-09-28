/**
 * 북마크 버튼 "Blog AI로 보내기".
 *
 * 사용자가 자기 브라우저로 보고 있는 글(네이버 블로그 등)에서 누르면, 그 화면에 보이는 제목·소제목·본문·사진 위치를
 * 읽어 Blog AI의 받기 화면(/import)으로 넘긴다. 서버는 그 글 주소에 접속하지 않는다(붙여넣기와 같은 방식, 한 번에 한 글).
 * 본문 형식은 워커의 붙여넣기 파서(references/extract.py from_text)와 같다: "# 소제목", "[사진]", 빈 줄로 문단 구분.
 */

// 브라우저 주소창에서 실행되므로 구형 문법만 쓴다. __APP__는 설치할 때의 Blog AI 주소로 바뀐다
const SOURCE = `(function(){
var APP='__APP__';
var doc=document,src=location.href;var f=document.getElementById('mainFrame');
try{if(f&&f.contentDocument&&f.contentDocument.body){doc=f.contentDocument;src=f.contentWindow.location.href;}}catch(e){}
var out=[];
function add(t){t=(t||'').replace(/\\u200b/g,'').replace(/[ \\t]+/g,' ').trim();if(t)out.push(t);}
function gap(){if(out.length&&out[out.length-1]!=='')out.push('');}
function cls(el){return ' '+(typeof el.className==='string'?el.className:'')+' ';}
function walk(el){
for(var c=el.firstElementChild;c;c=c.nextElementSibling){
var k=cls(c),t=c.tagName;
if(/^(SCRIPT|STYLE|NOSCRIPT|BUTTON|NAV|FOOTER|HEADER|IFRAME|FORM)$/.test(t))continue;
if(/ se-sectionTitle | se_sectionTitle /.test(k)||/^H[1-4]$/.test(t)){gap();add('# '+c.innerText.replace(/\\s+/g,' '));gap();continue;}
if(t==='IMG'||/ se-image | se-imageGroup | se-imageStrip | se-sticker | se_image /.test(k)){var n=t==='IMG'?1:Math.max(1,c.querySelectorAll('img').length);gap();for(var i=0;i<n;i++)add('[사진]');gap();continue;}
if(t==='P'||t==='LI'||/ se-text-paragraph /.test(k)){add(c.innerText);continue;}
if(!c.firstElementChild){add(c.innerText);continue;}
walk(c);
if(/ se-component /.test(k))gap();
}}
var root=doc.querySelector('.se-main-container')||doc.querySelector('#postViewArea')||doc.querySelector('.post_ct')||doc.querySelector('article')||doc.querySelector('main')||doc.body;
walk(root);
var tags=[].map.call(doc.querySelectorAll('.wrap_tag a,.post_tag a,.tag_area a,.se-hash-tag'),function(a){return (a.innerText||'').trim();}).filter(function(x){return /^#/.test(x);});
if(tags.length){gap();add(tags.join(' '));}
var tn=doc.querySelector('.se-title-text,.pcol1,.se_title,.tit_h3');
var data={type:'blog-ai-import',title:((tn&&tn.innerText)||doc.title||document.title).trim(),text:out.join('\\n').replace(/\\n{3,}/g,'\\n\\n').trim(),url:src};
if(data.text.length<20){alert('Blog AI: 이 화면에서 본문을 찾지 못했어요.');return;}
var w=window.open(APP+'/import','blog-ai-import');
if(!w){alert('Blog AI: 팝업이 막혔어요. 이 사이트의 팝업을 허용해 주세요.');return;}
var tries=0,timer=setInterval(function(){tries++;try{w.postMessage(data,APP);}catch(e){}if(tries>60)clearInterval(timer);},250);
window.addEventListener('message',function(e){if(e.origin===APP&&e.data&&e.data.type==='blog-ai-received')clearInterval(timer);});
})();`

/** 즐겨찾기바에 끌어다 놓을 javascript: 주소 */
export function bookmarkletHref(appOrigin: string): string {
  return 'javascript:' + encodeURIComponent(SOURCE.replace('__APP__', appOrigin))
}

export type ImportedPost = { title: string; text: string; url: string }

/** 받기 화면이 받은 메시지를 검사한다(형식이 맞지 않으면 무시) */
export function parseImportMessage(data: unknown): ImportedPost | null {
  if (!data || typeof data !== 'object') return null
  const m = data as Record<string, unknown>
  if (m.type !== 'blog-ai-import' || typeof m.text !== 'string' || m.text.trim().length < 20) return null
  return {
    title: typeof m.title === 'string' ? m.title.slice(0, 200) : '',
    text: m.text.slice(0, 100000),
    url: typeof m.url === 'string' && /^https?:\/\//.test(m.url) ? m.url.slice(0, 2000) : '',
  }
}

/** 미리보기용 요약: 글자 수·소제목·사진·해시태그 */
export function summarize(text: string) {
  const lines = text.split('\n')
  return {
    chars: text.replace(/\s/g, '').length,
    headings: lines.filter((l) => /^#\s/.test(l)).length,
    photos: lines.filter((l) => l.trim() === '[사진]').length,
    hashtags: (text.match(/(^|\s)#[0-9A-Za-z가-힣_]+/g) ?? []).length,
  }
}
