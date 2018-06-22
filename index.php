<?php
/*
The MIT License (MIT)
Copyright (c) 2018 AroDev

www.arionum.com

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM,
DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR
OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE
OR OTHER DEALINGS IN THE SOFTWARE.
*/

require_once(__DIR__.'/include/init.inc.php');
$block = new Block();
$current = $block->current();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Arionum Node</title>
    <style>
        .title:not(:last-child) {
            margin-bottom: 1.5rem;
        }

        body, h1, html {
            margin: 0;
            padding: 0;
        }

        h1 {
            font-size: 100%;
            font-weight: 400;
        }

        html {
            box-sizing: border-box;
        }

        *, ::after, ::before {
            box-sizing: inherit;
        }

        html {
            background-color: #fff;
            font-size: 16px;
            -moz-osx-font-smoothing: grayscale;
            -webkit-font-smoothing: antialiased;
            min-width: 300px;
            overflow-x: hidden;
            overflow-y: scroll;
            text-rendering: optimizeLegibility;
            -webkit-text-size-adjust: 100%;
            -moz-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }

        section {
            display: block;
        }

        body {
            font-family: "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Fira Sans", "Droid Sans", "Helvetica Neue", Helvetica, Arial, sans-serif;
        }

        body {
            color: #4a4a4a;
            font-size: 1rem;
            font-weight: 400;
            line-height: 1.5;
        }

        span {
            font-style: inherit;
            font-weight: inherit;
        }

        strong {
            color: #363636;
            font-weight: 700;
        }

        .container {
            margin: 0 auto;
            position: relative;
        }

        @media screen and (min-width: 1088px) {
            .container {
                max-width: 960px;
                width: 960px;
            }
        }

        @media screen and (min-width: 1280px) {
            .container {
                max-width: 1152px;
                width: 1152px;
            }
        }

        @media screen and (min-width: 1472px) {
            .container {
                max-width: 1344px;
                width: 1344px;
            }
        }

        .field.is-grouped {
            display: flex;
            justify-content: flex-start;
        }

        .field.is-grouped > .control {
            flex-shrink: 0;
        }

        .field.is-grouped > .control:not(:last-child) {
            margin-bottom: 0;
            margin-right: .75rem;
        }

        .field.is-grouped.is-grouped-multiline {
            flex-wrap: wrap;
        }

        .field.is-grouped.is-grouped-multiline > .control:last-child, .field.is-grouped.is-grouped-multiline > .control:not(:last-child) {
            margin-bottom: .75rem;
        }

        .field.is-grouped.is-grouped-multiline:last-child {
            margin-bottom: -.75rem;
        }

        .control {
            font-size: 1rem;
            position: relative;
            text-align: left;
        }

        .tags {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-start;
        }

        .tags .tag {
            margin-bottom: .5rem;
        }

        .tags .tag:not(:last-child) {
            margin-right: .5rem;
        }

        .tags:last-child {
            margin-bottom: -.5rem;
        }

        .tags.has-addons .tag {
            margin-right: 0;
        }

        .tags.has-addons .tag:not(:first-child) {
            border-bottom-left-radius: 0;
            border-top-left-radius: 0;
        }

        .tags.has-addons .tag:not(:last-child) {
            border-bottom-right-radius: 0;
            border-top-right-radius: 0;
        }

        .tag:not(body) {
            align-items: center;
            background-color: #f5f5f5;
            border-radius: 4px;
            color: #4a4a4a;
            display: inline-flex;
            font-size: .75rem;
            height: 2em;
            justify-content: center;
            line-height: 1.5;
            padding-left: .75em;
            padding-right: .75em;
            white-space: nowrap;
        }

        .tag:not(body).is-light {
            background-color: #f5f5f5;
            color: #363636;
        }

        .tag:not(body).is-info {
            background-color: #209cee;
            color: #fff;
        }

        .tag:not(body).is-success {
            background-color: #23d160;
            color: #fff;
        }

        .title {
            word-break: break-word;
        }

        .title {
            color: #363636;
            font-size: 2rem;
            font-weight: 600;
            line-height: 1.125;
        }

        .hero {
            align-items: stretch;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .hero.is-dark {
            background-color: #363636;
            color: #f5f5f5;
        }

        .hero.is-dark strong {
            color: inherit;
        }

        .hero.is-dark .title {
            color: #f5f5f5;
        }

        .hero.is-fullheight .hero-body {
            align-items: center;
            display: flex;
        }

        .hero.is-fullheight .hero-body > .container {
            flex-grow: 1;
            flex-shrink: 1;
        }

        .hero.is-fullheight {
            min-height: 100vh;
        }

        .hero-body {
            flex-grow: 1;
            flex-shrink: 0;
            padding: 3rem 1.5rem;
        }

        a {
            color: #3273dc;
            cursor: pointer;
            text-decoration: none;
        }

        a:hover {
            color: #363636;
        }

        a.is-dark {
            color: white;
        }
    </style>
</head>

<body>
<section class="hero is-dark is-fullheight">
    <div class="hero-body">
        <div class="container">
            <h1 class="title">Arionum Node</h1>

            <div class="field is-grouped is-grouped-multiline">
                <div class="control">
                    <div class="tags has-addons">
                        <strong class="tag is-success">Current Block</strong>
                        <span class="tag is-light"><?= $current['height']; ?></span>
                    </div>
                </div>
                <div class="control">
                    <div class="tags has-addons">
                        <strong class="tag is-info">Public API</strong>
                        <span class="tag is-light"><?= ($_config['public_api']) ? 'yes' : 'no'; ?></span>
                    </div>
                </div>
                <div class="control">
                    <a class="tags is-dark" href="./doc/" target="_blank">
                        <strong class="tag is-info">Documentation</strong>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>
</body>
</html>
