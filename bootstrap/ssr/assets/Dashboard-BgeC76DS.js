import { jsxs, jsx } from "react/jsx-runtime";
import { A as ApplicationLogo } from "./ApplicationLogo-xMpxFOcX.js";
import { Link, Head } from "@inertiajs/react";
import { useEffect } from "react";
function TelegramMiniApp({ children }) {
  return /* @__PURE__ */ jsxs("div", { className: "flex min-h-screen flex-col items-center bg-gray-100 pt-6 sm:justify-center sm:pt-0 dark:bg-gray-900", children: [
    /* @__PURE__ */ jsx("div", { children: /* @__PURE__ */ jsx(Link, { href: "/", children: /* @__PURE__ */ jsx(ApplicationLogo, { className: "h-20 w-20 fill-current text-gray-500" }) }) }),
    /* @__PURE__ */ jsx("div", { className: "mt-6 w-full overflow-hidden bg-white px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg dark:bg-gray-800", children })
  ] });
}
function Dashboard() {
  useEffect(() => {
    console.log(window.Telegram.WebApp);
  }, []);
  return /* @__PURE__ */ jsxs(TelegramMiniApp, { children: [
    /* @__PURE__ */ jsx(Head, { title: "پنل مدیریت تلگرام", children: /* @__PURE__ */ jsx(
      "script",
      {
        src: "https://telegram.org/js/telegram-web-app.js?56",
        async: true
      }
    ) }),
    /* @__PURE__ */ jsx("div", { className: "py-12", children: /* @__PURE__ */ jsx("div", { className: "mx-auto max-w-7xl sm:px-6 lg:px-8", children: /* @__PURE__ */ jsxs("div", { className: "overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800", children: [
      /* @__PURE__ */ jsx("div", { className: "p-6 text-gray-900 dark:text-gray-100", children: "به پنل مدیریت خوش آمدید!" }),
      /* @__PURE__ */ jsx("div", { className: "p-6", children: /* @__PURE__ */ jsx("button", { className: "rounded bg-blue-500 px-4 py-2 text-white", children: "ارسال پیام همگانی" }) }),
      /* @__PURE__ */ jsx("div", { className: "p-6", children: /* @__PURE__ */ jsx("button", { className: "rounded bg-green-500 px-4 py-2 text-white", children: "مدیریت کاربران" }) }),
      /* @__PURE__ */ jsx("div", { className: "p-6", children: /* @__PURE__ */ jsx("button", { className: "rounded bg-red-500 px-4 py-2 text-white", children: "گزارشات" }) })
    ] }) }) })
  ] });
}
export {
  Dashboard as default
};
