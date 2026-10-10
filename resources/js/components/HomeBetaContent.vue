<script setup lang="ts">
import { computed, ref } from 'vue';
import { Bluetooth, ChevronRight, Headphones, Music2, Navigation, PackageCheck, Search, ShieldCheck, Truck, Wrench } from '@lucide/vue';

type Product = { id: number; title: string; category: 'screen' | 'camera' | 'speaker' | 'accessory'; image: string | null; price: number | null };
const props = defineProps<{ products: Product[] }>();
const emit = defineEmits<{ select: [product: Product] }>();
const all = ref(false);
const products = computed(() => all.value ? props.products : props.products.slice(0, 4));
const euro = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' });
const paths = [
    { title: 'Per marca', text: 'Scegli la tua auto', image: 'per-marca', href: '/configurator?lang=it&mode=specific&pick=brand' },
    { title: 'Universali', text: 'Soluzioni per ogni auto', image: 'universali', href: '/configurator?lang=it&mode=universal' },
];
const benefits = [
    { icon: ShieldCheck, title: 'Compatibilità verificata', text: 'Trova la soluzione per la tua auto' },
    { icon: Headphones, title: 'Assistenza dedicata', text: 'Prima, durante e dopo l’acquisto' },
    { icon: Wrench, title: 'Installatori professionisti', text: 'Scopri le opzioni di installazione' },
];
</script>

<template>
    <main class="home-beta">
        <section class="home-hero" aria-labelledby="home-title">
            <img src="/images/home-beta/hero.webp" alt="Autoradio Android e strada costiera italiana al tramonto" fetchpriority="high" class="home-hero-image" />
            <div class="home-hero-shade"></div>
            <div class="home-hero-content">
                <p class="home-beta-label">LA TUA AUTO, UNA NUOVA ESPERIENZA <span>Beta</span></p>
                <h1 id="home-title">Ogni auto merita<br>la sua autoradio</h1>
                <p class="home-hero-description">Autoradio, CarPlay e Android Auto<br>con assistenza dedicata</p>
                <a href="/configurator?lang=it&mode=specific" class="home-cta"><Search :size="25" /><span>TROVA LA TUA AUTORADIO</span><ChevronRight :size="25" /></a>
                <div class="home-features" aria-label="Tecnologie disponibili">
                    <span><Music2 class="home-feature-icon carplay" />Apple CarPlay</span>
                    <span><Navigation class="home-feature-icon android" />Android Auto</span>
                    <span><Bluetooth class="home-feature-icon bluetooth" />Bluetooth</span>
                </div>
            </div>
        </section>

        <div class="home-container">
            <nav class="home-paths" aria-label="Trova la tua autoradio">
                <a v-for="path in paths" :key="path.title" :href="path.href" class="home-path">
                    <img :src="`/images/home-beta/${path.image}.webp`" alt="" loading="lazy" />
                    <div class="home-path-shade"></div>
                    <div class="home-path-content"><div><h2>{{ path.title }}</h2><p>{{ path.text }}</p></div><ChevronRight :size="25" /></div>
                </a>
            </nav>

            <section id="in-stock" class="home-stock" aria-labelledby="stock-title">
                <div class="home-stock-heading"><h2 id="stock-title">IN STOCK <span>·</span> CONSEGNA RAPIDA</h2><button v-if="props.products.length > 4" type="button" :aria-expanded="all" aria-controls="stock-grid" @click="all = !all">{{ all ? 'Mostra meno' : 'Vedi tutti' }}<ChevronRight :size="22" /></button></div>
                <div v-if="products.length" id="stock-grid" class="home-stock-grid">
                    <button v-for="product in products" :key="product.id" type="button" class="home-product" @click="emit('select', product)">
                        <span class="home-stock-badge"><PackageCheck :size="25" /><span>PRONTA<br>CONSEGNA</span><Truck :size="26" class="home-truck" /></span>
                        <div class="home-product-image"><img v-if="product.image" :src="product.image" :alt="product.title" loading="lazy" /><PackageCheck v-else :size="68" class="text-neutral-600" /></div>
                        <div class="home-product-title"><h3>{{ product.title }}</h3><ChevronRight :size="23" /></div>
                        <p v-if="product.price !== null" class="home-product-price">Da {{ euro.format(product.price) }}</p>
                        <div class="home-product-rule"><span></span></div>
                    </button>
                </div>
                <div v-else class="home-stock-empty"><PackageCheck :size="32" /><div><h3>La prossima autoradio ti aspetta.</h3><p>Contattaci per conoscere le disponibilità e trovare la soluzione per la tua auto.</p></div><a href="https://wa.me/393514346911">Chiedi disponibilità <ChevronRight :size="20" /></a></div>
            </section>
        </div>

        <section class="home-benefits" aria-label="Al tuo fianco, a ogni passo"><div class="home-container home-benefits-grid"><div v-for="benefit in benefits" :key="benefit.title" class="home-benefit"><component :is="benefit.icon" :size="48" :stroke-width="1.6" /><div><h2>{{ benefit.title }}</h2><p>{{ benefit.text }}</p></div></div></div></section>
    </main>
</template>

<style scoped>
.home-beta{background:#101010;color:#fff}.home-container{max-width:1200px;margin:auto;padding:0 28px}.home-hero{position:relative;min-height:660px;display:flex;align-items:center;isolation:isolate;overflow:hidden}.home-hero-image{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center 55%;z-index:-2}.home-hero-shade{position:absolute;inset:0;background:linear-gradient(90deg,rgba(0,0,0,.88),rgba(0,0,0,.58) 43%,rgba(0,0,0,.06) 78%),linear-gradient(0deg,#101010,transparent 25%);z-index:-1}.home-hero-content{width:100%;max-width:1200px;margin:auto;padding:72px 28px 60px}.home-beta-label{display:flex;align-items:center;gap:12px;font-size:11px;letter-spacing:.15em;color:#dedede;margin-bottom:24px}.home-beta-label span{font-size:10px;letter-spacing:.06em;border:1px solid #ffffff40;border-radius:20px;padding:3px 9px}.home-hero h1{font-size:clamp(38px,5vw,68px);line-height:1.04;font-weight:760;letter-spacing:-.035em;max-width:650px}.home-hero-description{font-size:clamp(17px,2.1vw,24px);line-height:1.4;margin-top:28px;color:#eee}.home-cta{margin-top:30px;display:inline-flex;align-items:center;gap:13px;padding:19px 24px;border-radius:12px;background:linear-gradient(100deg,#14b83f,#19bf46);font-size:17px;font-weight:700;box-shadow:0 8px 35px #0cb63d20;transition:background .2s,transform .2s}.home-cta:hover{background:#19d653;transform:translateY(-2px)}.home-features{display:flex;gap:27px;align-items:center;margin-top:30px;font-size:14px;font-weight:500}.home-features>span{display:flex;align-items:center;gap:10px}.home-feature-icon{width:33px;height:38px;padding:7px;border-radius:10px}.carplay{background:#21bd42}.android{color:#30b6ff;padding:2px}.bluetooth{background:#008dff}.home-paths{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;padding-top:26px}.home-path{position:relative;min-height:260px;overflow:hidden;border:1px solid #383838;border-radius:16px;transition:transform .2s,border-color .2s;isolation:isolate}.home-path:hover{transform:translateY(-4px);border-color:#28db73}.home-path img{position:absolute;inset:0;z-index:-2;width:100%;height:100%;object-fit:cover;object-position:50% 35%}.home-path-shade{position:absolute;inset:0;background:linear-gradient(0deg,#101010 5%,#101010b0 35%,transparent 75%);z-index:-1}.home-path-content{position:absolute;inset:auto 22px 20px;display:flex;justify-content:space-between;align-items:center;gap:12px}.home-path h2{font-size:25px;font-weight:700;line-height:1.15}.home-path p{font-size:17px;margin-top:5px;color:#d0d0d0}.home-stock{padding:68px 0 76px;scroll-margin-top:24px}.home-stock-heading{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:28px}.home-stock-heading h2{font-size:clamp(22px,3vw,34px);font-weight:750;letter-spacing:-.02em;line-height:1.2}.home-stock-heading h2 span{color:#b7b7b7}.home-stock-heading button{display:flex;align-items:center;gap:7px;color:#2be481;font-size:17px;font-weight:650;white-space:nowrap}.home-stock-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px}.home-product{display:flex;flex-direction:column;text-align:left;border:1px solid #303030;border-radius:15px;background:linear-gradient(140deg,#212121,#141414);padding:13px;overflow:hidden;transition:border-color .2s,transform .2s}.home-product:hover{border-color:#2be481;transform:translateY(-3px)}.home-stock-badge{display:flex;align-items:center;gap:7px;padding:8px;border-radius:9px;background:linear-gradient(90deg,#116e3d,#13332080);font-size:10px;font-weight:750;line-height:1.1}.home-truck{margin-left:auto;flex-shrink:0}.home-product-image{height:220px;display:flex;align-items:center;justify-content:center;padding:15px 0}.home-product-image img{width:100%;height:100%;object-fit:contain;mix-blend-mode:normal}.home-product-title{display:flex;align-items:center;justify-content:space-between;gap:10px}.home-product-title h3{font-size:16px;line-height:1.35;font-weight:650;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}.home-product-title svg{flex-shrink:0}.home-product-price{margin-top:12px;color:#e4e4e4;font-size:15px;font-weight:600}.home-product-rule{margin-top:auto;padding-top:22px;padding-bottom:8px}.home-product-rule span{display:block;width:35px;height:3px;background:#26d465;border-radius:2px}.home-stock-empty{border:1px solid #2d2d2d;border-radius:15px;padding:28px;display:flex;align-items:center;gap:20px;background:#171717}.home-stock-empty>svg{color:#28de77;flex-shrink:0}.home-stock-empty h3{font-weight:650;font-size:18px}.home-stock-empty p{margin-top:6px;color:#aaa;line-height:1.6;font-size:14px}.home-stock-empty a{margin-left:auto;display:flex;align-items:center;gap:5px;color:#2be481;white-space:nowrap;font-size:14px}.home-benefits{border-top:1px solid #292929;padding:36px 0 42px}.home-benefits-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))}.home-benefit{display:flex;align-items:flex-start;gap:20px;padding:0 28px}.home-benefit:first-child{padding-left:0}.home-benefit+div{border-left:1px solid #303030}.home-benefit>svg{color:#2be481;flex-shrink:0}.home-benefit h2{font-size:19px;line-height:1.3;font-weight:650}.home-benefit p{font-size:16px;font-style:italic;color:#aaa;line-height:1.5;margin-top:8px}a:focus-visible,button:focus-visible{outline:2px solid #2be481;outline-offset:5px}
@media(max-width:767px){.home-container{padding:0 18px}.home-hero{min-height:560px;align-items:flex-end}.home-hero-content{padding:130px 22px 40px}.home-hero-image{object-position:62% center}.home-hero-shade{background:linear-gradient(0deg,#101010,rgba(0,0,0,.65) 35%,rgba(0,0,0,.3) 75%),linear-gradient(90deg,#0008,transparent)}.home-beta-label{font-size:9px;margin-bottom:20px;letter-spacing:.1em}.home-cta{font-size:14px;padding:17px 18px;gap:10px}.home-features{gap:15px;font-size:11px;margin-top:23px;flex-wrap:wrap}.home-features>span{gap:6px}.home-feature-icon{width:27px;height:32px;padding:5px}.home-paths{gap:10px;padding-top:18px}.home-path{min-height:185px;border-radius:12px}.home-path-content{inset:auto 12px 14px;gap:3px}.home-path-content>svg{width:16px}.home-path h2{font-size:17px}.home-path p{font-size:12px;line-height:1.4}.home-stock{padding:42px 0 46px}.home-stock-heading{align-items:flex-start;gap:10px;margin-bottom:20px}.home-stock-heading h2{font-size:22px;max-width:240px}.home-stock-heading button{font-size:13px;padding-top:4px}.home-stock-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.home-product-image{height:160px}.home-product-title h3{font-size:14px}.home-product{padding:10px}.home-stock-badge{font-size:9px;padding:7px;gap:5px}.home-stock-badge>svg{width:22px}.home-stock-empty{padding:20px;align-items:flex-start;flex-wrap:wrap}.home-stock-empty>div{flex:1}.home-stock-empty a{margin-left:52px}.home-benefits{padding:28px 0}.home-benefits-grid{grid-template-columns:1fr;gap:24px}.home-benefit,.home-benefit:first-child{padding:0;gap:18px}.home-benefit+div{border-left:0;border-top:1px solid #292929;padding-top:24px}.home-benefit h2{font-size:18px}.home-benefit p{font-size:14px}}
@media(prefers-reduced-motion:reduce){.home-path,.home-product,.home-cta{transition:none}.home-path:hover,.home-product:hover,.home-cta:hover{transform:none}}
</style>
