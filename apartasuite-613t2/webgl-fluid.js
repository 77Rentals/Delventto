"use strict";(function(e,t){typeof exports==`object`&&typeof module<`u`?module.exports=t():typeof define==`function`&&define.amd?define([],t):(e=typeof globalThis<`u`?globalThis:e||self,e.WebGLFluid=t())})(this,function(){function e(e,t){let n=e;ke(),t={IMMEDIATE:!0,TRIGGER:`hover`,AUTO:!1,INTERVAL:3e3,SIM_RESOLUTION:128,DYE_RESOLUTION:1024,CAPTURE_RESOLUTION:512,DENSITY_DISSIPATION:1,VELOCITY_DISSIPATION:.2,PRESSURE:.8,PRESSURE_ITERATIONS:20,CURL:30,SPLAT_RADIUS:.25,SPLAT_FORCE:6e3,SPLAT_COUNT:Number.parseInt(Math.random()*20)+5,SPLAT_COLOR:void 0,SHADING:!0,COLORFUL:!0,COLOR_UPDATE_SPEED:10,PAUSED:!1,BACK_COLOR:{r:0,g:0,b:0},TRANSPARENT:!1,BLOOM:!0,BLOOM_ITERATIONS:8,BLOOM_RESOLUTION:256,BLOOM_INTENSITY:.8,BLOOM_THRESHOLD:.6,BLOOM_SOFT_KNEE:.7,SUNRAYS:!0,SUNRAYS_RESOLUTION:196,SUNRAYS_WEIGHT:1,...t};function r(){this.id=-1,this.texcoordX=0,this.texcoordY=0,this.prevTexcoordX=0,this.prevTexcoordY=0,this.deltaX=0,this.deltaY=0,this.down=!1,this.moved=!1,this.color=Z()}let i=[],a=[],o=[];i.push(new r);let{gl:s,ext:c}=l(n);te()&&(t.DYE_RESOLUTION=512),c.supportLinearFiltering||(t.DYE_RESOLUTION=512,t.SHADING=!1,t.BLOOM=!1,t.SUNRAYS=!1);function l(e){let t={alpha:!0,depth:!1,stencil:!1,antialias:!1,preserveDrawingBuffer:!1},n=e.getContext(`webgl2`,t),r=!!n;r||(n=e.getContext(`webgl`,t)||e.getContext(`experimental-webgl`,t));let i,a;r?(n.getExtension(`EXT_color_buffer_float`),a=n.getExtension(`OES_texture_float_linear`)):(i=n.getExtension(`OES_texture_half_float`),a=n.getExtension(`OES_texture_half_float_linear`)),n.clearColor(0,0,0,1);let o=r?n.HALF_FLOAT:i.HALF_FLOAT_OES,s,c,l;return r?(s=u(n,n.RGBA16F,n.RGBA,o),c=u(n,n.RG16F,n.RG,o),l=u(n,n.R16F,n.RED,o)):(s=u(n,n.RGBA,n.RGBA,o),c=u(n,n.RGBA,n.RGBA,o),l=u(n,n.RGBA,n.RGBA,o)),{gl:n,ext:{formatRGBA:s,formatRG:c,formatR:l,halfFloatTexType:o,supportLinearFiltering:a}}}function u(e,t,n,r){if(!ee(e,t,n,r))switch(t){case e.R16F:return u(e,e.RG16F,e.RG,r);case e.RG16F:return u(e,e.RGBA16F,e.RGBA,r);default:return null}return{internalFormat:t,format:n}}function ee(e,t,n,r){let i=e.createTexture();e.bindTexture(e.TEXTURE_2D,i),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_MIN_FILTER,e.NEAREST),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_MAG_FILTER,e.NEAREST),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_WRAP_S,e.CLAMP_TO_EDGE),e.texParameteri(e.TEXTURE_2D,e.TEXTURE_WRAP_T,e.CLAMP_TO_EDGE),e.texImage2D(e.TEXTURE_2D,0,t,4,4,0,n,r,null);let a=e.createFramebuffer();return e.bindFramebuffer(e.FRAMEBUFFER,a),e.framebufferTexture2D(e.FRAMEBUFFER,e.COLOR_ATTACHMENT0,e.TEXTURE_2D,i,0),e.checkFramebufferStatus(e.FRAMEBUFFER)===e.FRAMEBUFFER_COMPLETE}function te(){return/Mobi|Android/i.test(navigator.userAgent)}class ne{constructor(e,t){this.vertexShader=e,this.fragmentShaderSource=t,this.programs=[],this.activeProgram=null,this.uniforms=[]}setKeywords(e){let t=0;for(let n=0;n<e.length;n++)t+=et(e[n]);let n=this.programs[t];if(!n){let r=m(s.FRAGMENT_SHADER,this.fragmentShaderSource,e);n=f(this.vertexShader,r),this.programs[t]=n}n!==this.activeProgram&&(this.uniforms=p(n),this.activeProgram=n)}bind(){s.useProgram(this.activeProgram)}}class d{constructor(e,t){this.uniforms={},this.program=f(e,t),this.uniforms=p(this.program)}bind(){s.useProgram(this.program)}}function f(e,t){let n=s.createProgram();if(s.attachShader(n,e),s.attachShader(n,t),s.linkProgram(n),!s.getProgramParameter(n,s.LINK_STATUS))throw s.getProgramInfoLog(n);return n}function p(e){let t=[],n=s.getProgramParameter(e,s.ACTIVE_UNIFORMS);for(let r=0;r<n;r++){let n=s.getActiveUniform(e,r).name;t[n]=s.getUniformLocation(e,n)}return t}function m(e,t,n){t=re(t,n);let r=s.createShader(e);if(s.shaderSource(r,t),s.compileShader(r),!s.getShaderParameter(r,s.COMPILE_STATUS))throw s.getShaderInfoLog(r);return r}function re(e,t){if(!t)return e;let n=``;return t.forEach(e=>{n+=`#define ${e}\n`}),n+e}let h=m(s.VERTEX_SHADER,`
    precision highp float;
    attribute vec2 aPosition;
    varying vec2 vUv;
    varying vec2 vL;
    varying vec2 vR;
    varying vec2 vT;
    varying vec2 vB;
    uniform vec2 texelSize;
    void main () {
        vUv = aPosition * 0.5 + 0.5;
        vL = vUv - vec2(texelSize.x, 0.0);
        vR = vUv + vec2(texelSize.x, 0.0);
        vT = vUv + vec2(0.0, texelSize.y);
        vB = vUv - vec2(0.0, texelSize.y);
        gl_Position = vec4(aPosition, 0.0, 1.0);
    }
`),ie=m(s.VERTEX_SHADER,`
    precision highp float;
    attribute vec2 aPosition;
    varying vec2 vUv;
    varying vec2 vL;
    varying vec2 vR;
    uniform vec2 texelSize;
    void main () {
        vUv = aPosition * 0.5 + 0.5;
        float offset = 1.33333333;
        vL = vUv - texelSize * offset;
        vR = vUv + texelSize * offset;
        gl_Position = vec4(aPosition, 0.0, 1.0);
    }
`),ae=m(s.FRAGMENT_SHADER,`
    precision mediump float;
    precision mediump sampler2D;
    varying vec2 vUv;
    varying vec2 vL;
    varying vec2 vR;
    uniform sampler2D uTexture;
    void main () {
        vec4 sum = texture2D(uTexture, vUv) * 0.29411764;
        sum += texture2D(uTexture, vL) * 0.35294117;
        sum += texture2D(uTexture, vR) * 0.35294117;
        gl_FragColor = sum;
    }
`),oe=m(s.FRAGMENT_SHADER,`
    precision mediump float;
    precision mediump sampler2D;
    varying highp vec2 vUv;
    uniform sampler2D uTexture;
    void main () {
        gl_FragColor = texture2D(uTexture, vUv);
    }
`),se=m(s.FRAGMENT_SHADER,`
    precision mediump float;
    precision mediump sampler2D;
    varying highp vec2 vUv;
    uniform sampler2D uTexture;
    uniform float value;
    void main () {
        gl_FragColor = value * texture2D(uTexture, vUv);
    }
`),ce=m(s.FRAGMENT_SHADER,`
    precision mediump float;
    uniform vec4 color;
    void main () {
        gl_FragColor = color;
    }
`),le=m(s.FRAGMENT_SHADER,t.TRANSPARENT?`
    precision highp float;
    precision highp sampler2D;
    varying vec2 vUv;
    uniform sampler2D uTexture;
    uniform float aspectRatio;
    #define SCALE 25.0
    void main () {
        vec2 uv = floor(vUv * SCALE * vec2(aspectRatio, 1.0));
        float v = mod(uv.x + uv.y, 2.0);
        v = v * 0.1 + 0.8;
        gl_FragColor = vec4(0.0, 0.0, 0.0, 0.0);
    }
`:`
    precision highp float;
    precision highp sampler2D;
    varying vec2 vUv;
    uniform sampler2D uTexture;
    uniform float aspectRatio;
    #define SCALE 25.0
    void main () {
        vec2 uv = floor(vUv * SCALE * vec2(aspectRatio, 1.0));
        float v = mod(uv.x + uv.y, 2.0);
        v = v * 0.1 + 0.8;
        gl_FragColor = vec4(vec3(v), 1.0);
    }
`),ue=m(s.FRAGMENT_SHADER,`
    precision mediump float;
    precision mediump sampler2D;
    varying vec2 vUv;
    uniform sampler2D uTexture;
    uniform vec3 curve;
    uniform float threshold;
    void main () {
        vec3 c = texture2D(uTexture, vUv).rgb;
        float br = max(c.r, max(c.g, c.b));
        float rq = clamp(br - curve.x, 0.0, curve.y);
        rq = curve.z * rq * rq;
        c *= max(rq, br - threshold) / max(br, 0.0001);
        gl_FragColor = vec4(c, 0.0);
    }
`),de=m(s.FRAGMENT_SHADER,`
    precision mediump float;
    precision mediump sampler2D;
    varying vec2 vL;
    varying vec2 vR;
    varying vec2 vT;
    varying vec2 vB;
    uniform sampler2D uTexture;
    void main () {
        vec4 sum = vec4(0.0);
        sum += texture2D(uTexture, vL);
        sum += texture2D(uTexture, vR);
        sum += texture2D(uTexture, vT);
        sum += texture2D(uTexture, vB);
        sum *= 0.25;
        gl_FragColor = sum;
    }
`),fe=m(s.FRAGMENT_SHADER,`
    precision mediump float;
    precision mediump sampler2D;
    varying vec2 vL;
    varying vec2 vR;
    varying vec2 vT;
    varying vec2 vB;
    uniform sampler2D uTexture;
    uniform float intensity;
    void main () {
        vec4 sum = vec4(0.0);
        sum += texture2D(uTexture, vL);
        sum += texture2D(uTexture, vR);
        sum += texture2D(uTexture, vT);
        sum += texture2D(uTexture, vB);
        sum *= 0.25;
        gl_FragColor = sum * intensity;
    }
`),pe=m(s.FRAGMENT_SHADER,`
    precision highp float;
    precision highp sampler2D;
    varying vec2 vUv;
    uniform sampler2D uTexture;
    void main () {
        vec4 c = texture2D(uTexture, vUv);
        float br = max(c.r, max(c.g, c.b));
        c.a = 1.0 - min(max(br * 20.0, 0.0), 0.8);
        gl_FragColor = c;
    }
`),me=m(s.FRAGMENT_SHADER,`
    precision highp float;
    precision highp sampler2D;
    varying vec2 vUv;
    uniform sampler2D uTexture;
    uniform float weight;
    #define ITERATIONS 16
    void main () {
        float Density = 0.3;
        float Decay = 0.95;
        float Exposure = 0.7;
        vec2 coord = vUv;
        vec2 dir = vUv - 0.5;
        dir *= 1.0 / float(ITERATIONS) * Density;
        float illuminationDecay = 1.0;
        float color = texture2D(uTexture, vUv).a;
        for (int i = 0; i < ITERATIONS; i++)
        {
            coord -= dir;
            float col = texture2D(uTexture, coord).a;
            color += col * illuminationDecay * weight;
            illuminationDecay *= Decay;
        }
        gl_FragColor = vec4(color * Exposure, 0.0, 0.0, 1.0);
    }
`),he=m(s.FRAGMENT_SHADER,`
    precision highp float;
    precision highp sampler2D;
    varying vec2 vUv;
    uniform sampler2D uTarget;
    uniform float aspectRatio;
    uniform vec3 color;
    uniform vec2 point;
    uniform float radius;
    void main () {
        vec2 p = vUv - point.xy;
        p.x *= aspectRatio;
        vec3 splat = exp(-dot(p, p) / radius) * color;
        vec3 base = texture2D(uTarget, vUv).xyz;
        gl_FragColor = vec4(base + splat, 1.0);
    }
`),ge=m(s.FRAGMENT_SHADER,`
    precision highp float;
    precision highp sampler2D;
    varying vec2 vUv;
    uniform sampler2D uVelocity;
    uniform sampler2D uSource;
    uniform vec2 texelSize;
    uniform vec2 dyeTexelSize;
    uniform float dt;
    uniform float dissipation;
    vec4 bilerp (sampler2D sam, vec2 uv, vec2 tsize) {
        vec2 st = uv / tsize - 0.5;
        vec2 iuv = floor(st);
        vec2 fuv = fract(st);
        vec4 a = texture2D(sam, (iuv + vec2(0.5, 0.5)) * tsize);
        vec4 b = texture2D(sam, (iuv + vec2(1.5, 0.5)) * tsize);
        vec4 c = texture2D(sam, (iuv + vec2(0.5, 1.5)) * tsize);
        vec4 d = texture2D(sam, (iuv + vec2(1.5, 1.5)) * tsize);
        return mix(mix(a, b, fuv.x), mix(c, d, fuv.x), fuv.y);
    }
    void main () {
    #ifdef MANUAL_FILTERING
        vec2 coord = vUv - dt * bilerp(uVelocity, vUv, texelSize).xy * texelSize;
        vec4 result = bilerp(uSource, coord, dyeTexelSize);
    #else
        vec2 coord = vUv - dt * texture2D(uVelocity, vUv).xy * texelSize;
        vec4 result = texture2D(uSource, coord);
    #endif
        float decay = 1.0 + dissipation * dt;
        gl_FragColor = result / decay;
    }`,c.supportLinearFiltering?null:[`MANUAL_FILTERING`]),_e=m(s.FRAGMENT_SHADER,`
    precision mediump float;
    precision mediump sampler2D;
    varying highp vec2 vUv;
    varying highp vec2 vL;
    varying highp vec2 vR;
    varying highp vec2 vT;
    varying highp vec2 vB;
    uniform sampler2D uVelocity;
    void main () {
        float L = texture2D(uVelocity, vL).x;
        float R = texture2D(uVelocity, vR).x;
        float T = texture2D(uVelocity, vT).y;
        float B = texture2D(uVelocity, vB).y;
        vec2 C = texture2D(uVelocity, vUv).xy;
        if (vL.x < 0.0) { L = -C.x; }
        if (vR.x > 1.0) { R = -C.x; }
        if (vT.y > 1.0) { T = -C.y; }
        if (vB.y < 0.0) { B = -C.y; }
        float div = 0.5 * (R - L + T - B);
        gl_FragColor = vec4(div, 0.0, 0.0, 1.0);
    }
`),ve=m(s.FRAGMENT_SHADER,`
    precision mediump float;
    precision mediump sampler2D;
    varying highp vec2 vUv;
    varying highp vec2 vL;
    varying highp vec2 vR;
    varying highp vec2 vT;
    varying highp vec2 vB;
    uniform sampler2D uVelocity;
    void main () {
        float L = texture2D(uVelocity, vL).y;
        float R = texture2D(uVelocity, vR).y;
        float T = texture2D(uVelocity, vT).x;
        float B = texture2D(uVelocity, vB).x;
        float vorticity = R - L - T + B;
        gl_FragColor = vec4(0.5 * vorticity, 0.0, 0.0, 1.0);
    }
`),ye=m(s.FRAGMENT_SHADER,`
    precision highp float;
    precision highp sampler2D;
    varying vec2 vUv;
    varying vec2 vL;
    varying vec2 vR;
    varying vec2 vT;
    varying vec2 vB;
    uniform sampler2D uVelocity;
    uniform sampler2D uCurl;
    uniform float curl;
    uniform float dt;
    void main () {
        float L = texture2D(uCurl, vL).x;
        float R = texture2D(uCurl, vR).x;
        float T = texture2D(uCurl, vT).x;
        float B = texture2D(uCurl, vB).x;
        float C = texture2D(uCurl, vUv).x;
        vec2 force = 0.5 * vec2(abs(T) - abs(B), abs(R) - abs(L));
        force /= length(force) + 0.0001;
        force *= curl * C;
        force.y *= -1.0;
        vec2 vel = texture2D(uVelocity, vUv).xy;
        gl_FragColor = vec4(vel + force * dt, 0.0, 1.0);
    }
`),be=m(s.FRAGMENT_SHADER,`
    precision mediump float;
    precision mediump sampler2D;
    varying highp vec2 vUv;
    varying highp vec2 vL;
    varying highp vec2 vR;
    varying highp vec2 vT;
    varying highp vec2 vB;
    uniform sampler2D uPressure;
    uniform sampler2D uDivergence;
    void main () {
        float L = texture2D(uPressure, vL).x;
        float R = texture2D(uPressure, vR).x;
        float T = texture2D(uPressure, vT).x;
        float B = texture2D(uPressure, vB).x;
        float C = texture2D(uPressure, vUv).x;
        float divergence = texture2D(uDivergence, vUv).x;
        float pressure = (L + R + B + T - divergence) * 0.25;
        gl_FragColor = vec4(pressure, 0.0, 0.0, 1.0);
    }
`),xe=m(s.FRAGMENT_SHADER,`
    precision mediump float;
    precision mediump sampler2D;
    varying highp vec2 vUv;
    varying highp vec2 vL;
    varying highp vec2 vR;
    varying highp vec2 vT;
    varying highp vec2 vB;
    uniform sampler2D uPressure;
    uniform sampler2D uVelocity;
    void main () {
        float L = texture2D(uPressure, vL).x;
        float R = texture2D(uPressure, vR).x;
        float T = texture2D(uPressure, vT).x;
        float B = texture2D(uPressure, vB).x;
        vec2 velocity = texture2D(uVelocity, vUv).xy;
        velocity.xy -= vec2(R - L, T - B);
        gl_FragColor = vec4(velocity, 0.0, 1.0);
    }
`),g=(s.bindBuffer(s.ARRAY_BUFFER,s.createBuffer()),s.bufferData(s.ARRAY_BUFFER,new Float32Array([-1,-1,-1,1,1,1,1,-1]),s.STATIC_DRAW),s.bindBuffer(s.ELEMENT_ARRAY_BUFFER,s.createBuffer()),s.bufferData(s.ELEMENT_ARRAY_BUFFER,new Uint16Array([0,1,2,0,2,3]),s.STATIC_DRAW),s.vertexAttribPointer(0,2,s.FLOAT,!1,0,0),s.enableVertexAttribArray(0),e=>{s.bindFramebuffer(s.FRAMEBUFFER,e),s.drawElements(s.TRIANGLES,6,s.UNSIGNED_SHORT,0)}),_,v,y,b,x,S,C,w,T=Te(),E=new d(ie,ae),D=new d(h,oe),O=new d(h,se),k=new d(h,ce),A=new d(h,le),j=new d(h,ue),M=new d(h,de),N=new d(h,fe),P=new d(h,pe),F=new d(h,me),I=new d(h,he),L=new d(h,ge),R=new d(h,_e),z=new d(h,ve),B=new d(h,ye),V=new d(h,be),H=new d(h,xe),U=new ne(h,`
    precision highp float;
    precision highp sampler2D;
    varying vec2 vUv;
    varying vec2 vL;
    varying vec2 vR;
    varying vec2 vT;
    varying vec2 vB;
    uniform sampler2D uTexture;
    uniform sampler2D uBloom;
    uniform sampler2D uSunrays;
    uniform sampler2D uDithering;
    uniform vec2 ditherScale;
    uniform vec2 texelSize;
    vec3 linearToGamma (vec3 color) {
        color = max(color, vec3(0));
        return max(1.055 * pow(color, vec3(0.416666667)) - 0.055, vec3(0));
    }
    void main () {
        vec3 c = texture2D(uTexture, vUv).rgb;
    #ifdef SHADING
        vec3 lc = texture2D(uTexture, vL).rgb;
        vec3 rc = texture2D(uTexture, vR).rgb;
        vec3 tc = texture2D(uTexture, vT).rgb;
        vec3 bc = texture2D(uTexture, vB).rgb;
        float dx = length(rc) - length(lc);
        float dy = length(tc) - length(bc);
        vec3 n = normalize(vec3(dx, dy, length(texelSize)));
        vec3 l = vec3(0.0, 0.0, 1.0);
        float diffuse = clamp(dot(n, l) + 0.7, 0.7, 1.0);
        c *= diffuse;
    #endif
    #ifdef BLOOM
        vec3 bloom = texture2D(uBloom, vUv).rgb;
    #endif
    #ifdef SUNRAYS
        float sunrays = texture2D(uSunrays, vUv).r;
        c *= sunrays;
    #ifdef BLOOM
        bloom *= sunrays;
    #endif
    #endif
    #ifdef BLOOM
        float noise = texture2D(uDithering, vUv * ditherScale).r;
        noise = noise * 2.0 - 1.0;
        bloom += noise / 255.0;
        bloom = linearToGamma(bloom);
        c += bloom;
    #endif
        float a = max(c.r, max(c.g, c.b));
        gl_FragColor = vec4(c, a);
    }
`);function W(){let e=Q(t.SIM_RESOLUTION),n=Q(t.DYE_RESOLUTION),r=c.halfFloatTexType,i=c.formatRGBA,a=c.formatRG,o=c.formatR,l=c.supportLinearFiltering?s.LINEAR:s.NEAREST;_=_?q(_,n.width,n.height,i.internalFormat,i.format,r,l):K(n.width,n.height,i.internalFormat,i.format,r,l),v=v?q(v,e.width,e.height,a.internalFormat,a.format,r,l):K(e.width,e.height,a.internalFormat,a.format,r,l),y=G(e.width,e.height,o.internalFormat,o.format,r,s.NEAREST),b=G(e.width,e.height,o.internalFormat,o.format,r,s.NEAREST),x=K(e.width,e.height,o.internalFormat,o.format,r,s.NEAREST),Se(),Ce()}function Se(){let e=Q(t.BLOOM_RESOLUTION),n=c.halfFloatTexType,r=c.formatRGBA,i=c.supportLinearFiltering?s.LINEAR:s.NEAREST;S=G(e.width,e.height,r.internalFormat,r.format,n,i),o.length=0;for(let a=0;a<t.BLOOM_ITERATIONS;a++){let t=e.width>>a+1,s=e.height>>a+1;if(t<2||s<2)break;let c=G(t,s,r.internalFormat,r.format,n,i);o.push(c)}}function Ce(){let e=Q(t.SUNRAYS_RESOLUTION),n=c.halfFloatTexType,r=c.formatR,i=c.supportLinearFiltering?s.LINEAR:s.NEAREST;C=G(e.width,e.height,r.internalFormat,r.format,n,i),w=G(e.width,e.height,r.internalFormat,r.format,n,i)}function G(e,t,n,r,i,a){s.activeTexture(s.TEXTURE0);let o=s.createTexture();s.bindTexture(s.TEXTURE_2D,o),s.texParameteri(s.TEXTURE_2D,s.TEXTURE_MIN_FILTER,a),s.texParameteri(s.TEXTURE_2D,s.TEXTURE_MAG_FILTER,a),s.texParameteri(s.TEXTURE_2D,s.TEXTURE_WRAP_S,s.CLAMP_TO_EDGE),s.texParameteri(s.TEXTURE_2D,s.TEXTURE_WRAP_T,s.CLAMP_TO_EDGE),s.texImage2D(s.TEXTURE_2D,0,n,e,t,0,r,i,null);let c=s.createFramebuffer();return s.bindFramebuffer(s.FRAMEBUFFER,c),s.framebufferTexture2D(s.FRAMEBUFFER,s.COLOR_ATTACHMENT0,s.TEXTURE_2D,o,0),s.viewport(0,0,e,t),s.clear(s.COLOR_BUFFER_BIT),{texture:o,fbo:c,width:e,height:t,texelSizeX:1/e,texelSizeY:1/t,attach(e){return s.activeTexture(s.TEXTURE0+e),s.bindTexture(s.TEXTURE_2D,o),e}}}function K(e,t,n,r,i,a){let o=G(e,t,n,r,i,a),s=G(e,t,n,r,i,a);return{width:e,height:t,texelSizeX:o.texelSizeX,texelSizeY:o.texelSizeY,get read(){return o},set read(e){o=e},get write(){return s},set write(e){s=e},swap(){let e=o;o=s,s=e}}}function we(e,t,n,r,i,a,o){let c=G(t,n,r,i,a,o);return D.bind(),s.uniform1i(D.uniforms.uTexture,e.attach(0)),g(c.fbo),c}function q(e,t,n,r,i,a,o){return e.width===t&&e.height===n?e:(e.read=we(e.read,t,n,r,i,a,o),e.write=G(t,n,r,i,a,o),e.width=t,e.height=n,e.texelSizeX=1/t,e.texelSizeY=1/n,e)}function Te(e){let t=s.createTexture();s.bindTexture(s.TEXTURE_2D,t),s.texParameteri(s.TEXTURE_2D,s.TEXTURE_MIN_FILTER,s.LINEAR),s.texParameteri(s.TEXTURE_2D,s.TEXTURE_MAG_FILTER,s.LINEAR),s.texParameteri(s.TEXTURE_2D,s.TEXTURE_WRAP_S,s.REPEAT),s.texParameteri(s.TEXTURE_2D,s.TEXTURE_WRAP_T,s.REPEAT),s.texImage2D(s.TEXTURE_2D,0,s.RGB,1,1,0,s.RGB,s.UNSIGNED_BYTE,new Uint8Array([255,255,255]));let n={texture:t,width:1,height:1,attach(e){return s.activeTexture(s.TEXTURE0+e),s.bindTexture(s.TEXTURE_2D,t),e}},r=new Image;return r.onload=()=>{n.width=r.width,n.height=r.height,s.bindTexture(s.TEXTURE_2D,t),s.texImage2D(s.TEXTURE_2D,0,s.RGB,s.RGB,s.UNSIGNED_BYTE,r)},e&&(r.src=e),n}function Ee(){let e=[];t.SHADING&&e.push(`SHADING`),t.BLOOM&&e.push(`BLOOM`),t.SUNRAYS&&e.push(`SUNRAYS`),U.setKeywords(e)}Ee(),W(),t.IMMEDIATE&&Ve(t.SPLAT_COUNT);function J(){t.AUTO&&t.INTERVAL&&!t.PAUSED&&a.push(t.SPLAT_COUNT),setTimeout(J,t.INTERVAL)}setTimeout(J,t.INTERVAL);let Y=Date.now(),X=0;De();function De(){let e=Oe();ke()&&W(),Ae(e),je(),t.PAUSED||Me(e),Ne(null),requestAnimationFrame(De)}function Oe(){let e=Date.now(),t=(e-Y)/1e3;return t=Math.min(t,.016666),Y=e,t}function ke(){let e=$(n.clientWidth),t=$(n.clientHeight);return n.width!==e||n.height!==t?(n.width=e,n.height=t,!0):!1}function Ae(e){t.COLORFUL&&(X+=e*t.COLOR_UPDATE_SPEED,X>=1&&(X=Qe(X,0,1),i.forEach(e=>{e.color=Z()})))}function je(){a.length>0&&Ve(a.pop()),i.forEach(e=>{e.moved&&(e.moved=!1,Be(e))})}function Me(e){s.disable(s.BLEND),s.viewport(0,0,v.width,v.height),z.bind(),s.uniform2f(z.uniforms.texelSize,v.texelSizeX,v.texelSizeY),s.uniform1i(z.uniforms.uVelocity,v.read.attach(0)),g(b.fbo),B.bind(),s.uniform2f(B.uniforms.texelSize,v.texelSizeX,v.texelSizeY),s.uniform1i(B.uniforms.uVelocity,v.read.attach(0)),s.uniform1i(B.uniforms.uCurl,b.attach(1)),s.uniform1f(B.uniforms.curl,t.CURL),s.uniform1f(B.uniforms.dt,e),g(v.write.fbo),v.swap(),R.bind(),s.uniform2f(R.uniforms.texelSize,v.texelSizeX,v.texelSizeY),s.uniform1i(R.uniforms.uVelocity,v.read.attach(0)),g(y.fbo),O.bind(),s.uniform1i(O.uniforms.uTexture,x.read.attach(0)),s.uniform1f(O.uniforms.value,t.PRESSURE),g(x.write.fbo),x.swap(),V.bind(),s.uniform2f(V.uniforms.texelSize,v.texelSizeX,v.texelSizeY),s.uniform1i(V.uniforms.uDivergence,y.attach(0));for(let e=0;e<t.PRESSURE_ITERATIONS;e++)s.uniform1i(V.uniforms.uPressure,x.read.attach(1)),g(x.write.fbo),x.swap();H.bind(),s.uniform2f(H.uniforms.texelSize,v.texelSizeX,v.texelSizeY),s.uniform1i(H.uniforms.uPressure,x.read.attach(0)),s.uniform1i(H.uniforms.uVelocity,v.read.attach(1)),g(v.write.fbo),v.swap(),L.bind(),s.uniform2f(L.uniforms.texelSize,v.texelSizeX,v.texelSizeY),c.supportLinearFiltering||s.uniform2f(L.uniforms.dyeTexelSize,v.texelSizeX,v.texelSizeY);let n=v.read.attach(0);s.uniform1i(L.uniforms.uVelocity,n),s.uniform1i(L.uniforms.uSource,n),s.uniform1f(L.uniforms.dt,e),s.uniform1f(L.uniforms.dissipation,t.VELOCITY_DISSIPATION),g(v.write.fbo),v.swap(),s.viewport(0,0,_.width,_.height),c.supportLinearFiltering||s.uniform2f(L.uniforms.dyeTexelSize,_.texelSizeX,_.texelSizeY),s.uniform1i(L.uniforms.uVelocity,v.read.attach(0)),s.uniform1i(L.uniforms.uSource,_.read.attach(1)),s.uniform1f(L.uniforms.dissipation,t.DENSITY_DISSIPATION),g(_.write.fbo),_.swap()}function Ne(e){t.BLOOM&&Le(_.read,S),t.SUNRAYS&&(Re(_.read,_.write,C),ze(C,w,1)),!e||!t.TRANSPARENT?(s.blendFunc(s.ONE,s.ONE_MINUS_SRC_ALPHA),s.enable(s.BLEND)):s.disable(s.BLEND);let n=e?e.width:s.drawingBufferWidth,r=e?e.height:s.drawingBufferHeight;s.viewport(0,0,n,r);let i=e?e.fbo:null;t.TRANSPARENT||Pe(i,Ze(t.BACK_COLOR)),!e&&t.TRANSPARENT&&Fe(i),Ie(i,n,r)}function Pe(e,t){k.bind(),s.uniform4f(k.uniforms.color,t.r,t.g,t.b,1),g(e)}function Fe(e){A.bind(),s.uniform1f(A.uniforms.aspectRatio,n.width/n.height),g(e)}function Ie(e,n,r){if(U.bind(),t.SHADING&&s.uniform2f(U.uniforms.texelSize,1/n,1/r),s.uniform1i(U.uniforms.uTexture,_.read.attach(0)),t.BLOOM){s.uniform1i(U.uniforms.uBloom,S.attach(1)),s.uniform1i(U.uniforms.uDithering,T.attach(2));let e=$e(T,n,r);s.uniform2f(U.uniforms.ditherScale,e.x,e.y)}t.SUNRAYS&&s.uniform1i(U.uniforms.uSunrays,C.attach(3)),g(e)}function Le(e,n){if(o.length<2)return;let r=n;s.disable(s.BLEND),j.bind();let i=t.BLOOM_THRESHOLD*t.BLOOM_SOFT_KNEE+1e-4,a=t.BLOOM_THRESHOLD-i,c=i*2,l=.25/i;s.uniform3f(j.uniforms.curve,a,c,l),s.uniform1f(j.uniforms.threshold,t.BLOOM_THRESHOLD),s.uniform1i(j.uniforms.uTexture,e.attach(0)),s.viewport(0,0,r.width,r.height),g(r.fbo),M.bind();for(let e=0;e<o.length;e++){let t=o[e];s.uniform2f(M.uniforms.texelSize,r.texelSizeX,r.texelSizeY),s.uniform1i(M.uniforms.uTexture,r.attach(0)),s.viewport(0,0,t.width,t.height),g(t.fbo),r=t}s.blendFunc(s.ONE,s.ONE),s.enable(s.BLEND);for(let e=o.length-2;e>=0;e--){let t=o[e];s.uniform2f(M.uniforms.texelSize,r.texelSizeX,r.texelSizeY),s.uniform1i(M.uniforms.uTexture,r.attach(0)),s.viewport(0,0,t.width,t.height),g(t.fbo),r=t}s.disable(s.BLEND),N.bind(),s.uniform2f(N.uniforms.texelSize,r.texelSizeX,r.texelSizeY),s.uniform1i(N.uniforms.uTexture,r.attach(0)),s.uniform1f(N.uniforms.intensity,t.BLOOM_INTENSITY),s.viewport(0,0,n.width,n.height),g(n.fbo)}function Re(e,n,r){s.disable(s.BLEND),P.bind(),s.uniform1i(P.uniforms.uTexture,e.attach(0)),s.viewport(0,0,n.width,n.height),g(n.fbo),F.bind(),s.uniform1f(F.uniforms.weight,t.SUNRAYS_WEIGHT),s.uniform1i(F.uniforms.uTexture,n.attach(0)),s.viewport(0,0,r.width,r.height),g(r.fbo)}function ze(e,t,n){E.bind();for(let r=0;r<n;r++)s.uniform2f(E.uniforms.texelSize,e.texelSizeX,0),s.uniform1i(E.uniforms.uTexture,e.attach(0)),g(t.fbo),s.uniform2f(E.uniforms.texelSize,0,e.texelSizeY),s.uniform1i(E.uniforms.uTexture,t.attach(0)),g(e.fbo)}function Be(e){let n=e.deltaX*t.SPLAT_FORCE,r=e.deltaY*t.SPLAT_FORCE;He(e.texcoordX,e.texcoordY,n,r,e.color)}function Ve(e){for(let n=0;n<e;n++){let e=Z();t.SPLAT_COLOR||(e.r*=10,e.g*=10,e.b*=10),He(Math.random(),Math.random(),1e3*(Math.random()-.5),1e3*(Math.random()-.5),e)}}function He(e,r,i,a,o){s.viewport(0,0,v.width,v.height),I.bind(),s.uniform1i(I.uniforms.uTarget,v.read.attach(0)),s.uniform1f(I.uniforms.aspectRatio,n.width/n.height),s.uniform2f(I.uniforms.point,e,r),s.uniform3f(I.uniforms.color,i,a,0),s.uniform1f(I.uniforms.radius,Ue(t.SPLAT_RADIUS/100)),g(v.write.fbo),v.swap(),s.viewport(0,0,_.width,_.height),s.uniform1i(I.uniforms.uTarget,_.read.attach(0)),s.uniform3f(I.uniforms.color,o.r,o.g,o.b),g(_.write.fbo),_.swap()}function Ue(e){let t=n.width/n.height;return t>1&&(e*=t),e}n.addEventListener(`mousedown`,e=>{let t=$(e.offsetX),n=$(e.offsetY),a=i.find(e=>e.id===-1);a||(a=new r),We(a,-1,t,n)}),setTimeout(()=>{n.addEventListener(`mousemove`,e=>{let t=$(e.offsetX),n=$(e.offsetY);Ge(i[0],t,n)})},500),window.addEventListener(`mouseup`,()=>{Ke(i[0])}),n.addEventListener(`touchstart`,e=>{e.preventDefault();let t=e.targetTouches;for(;t.length>=i.length;)i.push(new r);for(let e=0;e<t.length;e++){let n=$(t[e].pageX),r=$(t[e].pageY);We(i[e+1],t[e].identifier,n,r)}}),n.addEventListener(`touchmove`,e=>{e.preventDefault();let t=e.targetTouches;for(let e=0;e<t.length;e++){let n=$(t[e].pageX),r=$(t[e].pageY);Ge(i[e+1],n,r)}},!1),window.addEventListener(`touchend`,e=>{let t=e.changedTouches;for(let e=0;e<t.length;e++)Ke(i.find(n=>n.id===t[e].identifier))}),window.addEventListener(`keydown`,e=>{e.code===`KeyP`&&(t.PAUSED=!t.PAUSED),e.key===` `&&a.push(Number.parseInt(Math.random()*20)+5)});function We(e,r,i,a){e.id=r,e.down=!0,e.moved=!1,e.texcoordX=i/n.width,e.texcoordY=1-a/n.height,e.prevTexcoordX=e.texcoordX,e.prevTexcoordY=e.texcoordY,e.deltaX=0,e.deltaY=0,t.COLORFUL&&(e.color=Z())}function Ge(e,r,i){t.TRIGGER===`click`&&(e.moved=e.down),e.prevTexcoordX=e.texcoordX,e.prevTexcoordY=e.texcoordY,e.texcoordX=r/n.width,e.texcoordY=1-i/n.height,e.deltaX=qe(e.texcoordX-e.prevTexcoordX),e.deltaY=Je(e.texcoordY-e.prevTexcoordY),t.TRIGGER===`hover`&&(e.moved=Math.abs(e.deltaX)>0||Math.abs(e.deltaY)>0)}function Ke(e){e.down=!1}function qe(e){let t=n.width/n.height;return t<1&&(e*=t),e}function Je(e){let t=n.width/n.height;return t>1&&(e/=t),e}function Ye(){let e=Xe(Math.random(),1,1);return e.r*=.15,e.g*=.15,e.b*=.15,e}function Z(){let e=t.SPLAT_COLOR||Ye();return{r:e.r,g:e.g,b:e.b}}function Xe(e,t,n){let r,i,a,o=Math.floor(e*6),s=e*6-o,c=n*(1-t),l=n*(1-s*t),u=n*(1-(1-s)*t);switch(o%6){case 0:r=n,i=u,a=c;break;case 1:r=l,i=n,a=c;break;case 2:r=c,i=n,a=u;break;case 3:r=c,i=l,a=n;break;case 4:r=u,i=c,a=n;break;case 5:r=n,i=c,a=l;break}return{r,g:i,b:a}}function Ze(e){return{r:e.r/255,g:e.g/255,b:e.b/255}}function Qe(e,t,n){let r=n-t;return r===0?t:(e-t)%r+t}function Q(e){let t=s.drawingBufferWidth/s.drawingBufferHeight;t<1&&(t=1/t);let n=Math.round(e),r=Math.round(e*t);return s.drawingBufferWidth>s.drawingBufferHeight?{width:r,height:n}:{width:n,height:r}}function $e(e,t,n){return{x:t/e.width,y:n/e.height}}function $(e){let t=window.devicePixelRatio||1;return Math.floor(e*t)}function et(e){if(e.length===0)return 0;let t=0;for(let n=0;n<e.length;n++)t=(t<<5)-t+e.charCodeAt(n),t|=0;return t}}return e});
//# sourceMappingURL=webgl-fluid.umd.js.map