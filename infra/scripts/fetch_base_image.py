"""Docker Hub 공식 이미지를 레지스트리 API로 받아 `docker load`용 tar로 만든다.

Docker Desktop 엔진의 프록시(http.docker.internal:3128)가 응답하지 않아 빌드가 "load metadata ... DeadlineExceeded"로
실패할 때 쓴다. 컨테이너 네트워크는 정상이므로 컨테이너 안에서 받는다. 사용: make base-images
"""
import hashlib, io, json, sys, tarfile, urllib.request

repo, tag, out = sys.argv[1], sys.argv[2], sys.argv[3]
ARCH = sys.argv[4] if len(sys.argv) > 4 else "arm64"
REG = "https://registry-1.docker.io/v2/library/" + repo
token = json.load(urllib.request.urlopen(
    f"https://auth.docker.io/token?service=registry.docker.io&scope=repository:library/{repo}:pull"))["token"]

def get(url, accept=None):
    req = urllib.request.Request(url, headers={"Authorization": f"Bearer {token}", **({"Accept": accept} if accept else {})})
    return urllib.request.urlopen(req, timeout=120).read()

index_types = "application/vnd.oci.image.index.v1+json,application/vnd.docker.distribution.manifest.list.v2+json"
manifest_types = "application/vnd.oci.image.manifest.v1+json,application/vnd.docker.distribution.manifest.v2+json"
index = json.loads(get(f"{REG}/manifests/{tag}", index_types + "," + manifest_types))
if "manifests" in index:
    entry = next(m for m in index["manifests"]
                 if m.get("platform", {}).get("os") == "linux" and m["platform"].get("architecture") == ARCH)
    manifest = json.loads(get(f"{REG}/manifests/{entry['digest']}", manifest_types))
else:
    manifest = index

def blob(digest):
    data = get(f"{REG}/blobs/{digest}")
    assert "sha256:" + hashlib.sha256(data).hexdigest() == digest, f"digest mismatch {digest}"
    return data

with tarfile.open(out, "w") as tar:
    def add(name, data):
        info = tarfile.TarInfo(name); info.size = len(data); tar.addfile(info, io.BytesIO(data))
    config_digest = manifest["config"]["digest"]
    add(config_digest[7:] + ".json", blob(config_digest))
    layers = []
    for i, layer in enumerate(manifest["layers"]):
        name = f"{layer['digest'][7:]}/layer.tar"
        add(name, blob(layer["digest"]))  # 압축된 레이어도 docker load가 풀어 준다
        layers.append(name)
        print(f"  {repo}:{tag} layer {i + 1}/{len(manifest['layers'])}", flush=True)
    add("manifest.json", json.dumps([{"Config": config_digest[7:] + ".json", "RepoTags": [f"{repo}:{tag}"], "Layers": layers}]).encode())
print("done", out)
