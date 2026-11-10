import { PROJECT } from '../data.js';
import { originOf, playWelcome } from '../curtain.js';
import { logo, toast } from '../ui.js';
import { navigate } from '../router.js';
import { store } from '../store.js';
import { esc, firstName } from '../util.js';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const WAIT_MS = 400;

export default function login() {
    return {
        html: `<div class="login">
            <div class="login-brand rise">
                ${logo()}
                <span class="brand-kicker">${PROJECT.kicker}</span>
                <h1>${PROJECT.name}</h1>
                <p>${PROJECT.title} do projeto de extensão da Feevale</p>
            </div>

            <form class="card form rise" id="login-form" novalidate>
                <div class="field">
                    <label for="email">E-mail</label>
                    <input class="input" id="email" name="email" type="email" inputmode="email" autocomplete="username" value="${store.isDemo ? 'admin@agenda.local' : ''}" required>
                    <div role="status">
                        <p class="hint-text" id="awaiting-password" hidden>Esta conta ainda não tem senha. <button class="link small" type="button" id="create-password">Criar senha</button></p>
                    </div>
                </div>
                <div class="field">
                    <label for="password">Senha</label>
                    <input class="input" id="password" name="password" type="password" autocomplete="current-password" value="${store.isDemo ? 'password' : ''}" required>
                </div>
                <p class="error-text" id="login-error" role="alert" hidden></p>
                <button class="btn btn-primary btn-block" type="submit">Entrar</button>
                <button class="link small" type="button" id="forgot">Esqueci minha senha</button>
            </form>

            <p class="demo-note rise" ${store.isDemo ? '' : 'hidden'}>Versão de demonstração: os dados são fictícios e ficam salvos só neste aparelho. O login já vem preenchido.</p>

            <p class="login-foot">Projeto de extensão do curso de Ciências Contábeis<br>Universidade Feevale · ${esc(PROJECT.coordinator)}</p>
        </div>`,

        mount(root) {
            const form = root.querySelector('#login-form');
            const error = root.querySelector('#login-error');
            const hint = root.querySelector('#awaiting-password');
            let typed = '';
            let timer = null;

            const emailChanged = () => {
                const email = form.email.value.trim().toLowerCase();
                if (email === typed) return;
                clearTimeout(timer);
                typed = email;
                hint.hidden = true;
                if (!EMAIL.test(email)) return;
                timer = setTimeout(async () => {
                    const awaiting = await store.awaitingPassword(email);
                    if (email === typed) hint.hidden = !awaiting;
                }, WAIT_MS);
            };

            const sendLink = async (button, sent) => {
                if (!form.email.reportValidity()) return;
                button.disabled = true;
                try {
                    await store.forgotPassword(form.email.value);
                    toast(sent);
                } catch (problem) {
                    toast(problem.message);
                } finally {
                    button.disabled = false;
                }
            };

            form.email.addEventListener('input', emailChanged);
            form.email.addEventListener('change', emailChanged);

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (!form.reportValidity()) return;
                const button = form.querySelector('[type=submit]');
                if (button.disabled) return;
                button.disabled = true;
                button.textContent = 'Entrando…';
                error.hidden = true;
                let user;
                try {
                    user = await store.login(form.email.value, form.password.value);
                } catch (problem) {
                    error.textContent = problem.message;
                    error.hidden = false;
                    return;
                } finally {
                    button.disabled = false;
                    button.textContent = 'Entrar';
                }

                if (!user) {
                    error.textContent = 'E-mail ou senha incorretos. Confira os dados e tente de novo.';
                    error.hidden = false;
                    return;
                }

                navigate('/');
                playWelcome(firstName(user.name), originOf(button));
            });

            root.querySelector('#forgot').addEventListener('click', (event) =>
                sendLink(event.currentTarget, store.isDemo ? 'Recuperação de senha disponível quando o servidor estiver conectado.' : 'Se o e-mail estiver cadastrado, você receberá as instruções para redefinir sua senha.'),
            );

            root.querySelector('#create-password').addEventListener('click', (event) =>
                sendLink(event.currentTarget, 'Enviamos um link para este e-mail. Abra a mensagem para criar sua senha.'),
            );
        },
    };
}
